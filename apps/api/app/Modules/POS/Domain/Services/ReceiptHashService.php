<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Domain\Enums\SealedHashAlgorithm;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Service for calculating and verifying POS receipt hash chains (NF525 compliance).
 *
 * This service implements SHA-256 hash chains for POS receipts, ensuring:
 * - Receipts cannot be modified after creation
 * - Receipts cannot be deleted without detection
 * - Receipts cannot be inserted into the middle of the chain
 * - Each terminal maintains an independent hash chain
 *
 * **Phase 1 §5.0 D1 rebuild (Task 30).** Verification now re-hashes the
 * verbatim `fiscal_events.canonical_bytes` for every Phase-1 row instead
 * of recomputing the legacy `serializeForHashing()` shape from POS Domain
 * model fields. The chain truth lives on `fiscal_events` (Task 7), not
 * the projection mirror — tampering with `pos_receipts.total` (or any
 * other mirror column) MUST NOT cause a false chain break, while
 * tampering with `canonical_bytes` MUST be caught on rehash.
 *
 * Legacy chain rows (rows where `fiscal_event_id IS NULL` — i.e. rows
 * authored by the pre-Phase-1 server path) are still verified via the
 * legacy `calculateHash()` recomputation. Mirrors the Task 21 R2 carve-out
 * pattern: `whereNotNull('fiscal_event_id')` selects projection rows for
 * the §13 fiscal-events anchored verifier; `whereNull('fiscal_event_id')`
 * selects legacy rows for the original NF525 pipe-separated recomputation.
 * Both paths coexist until the legacy verifier retires post-Phase-1.
 *
 * Hash Input Format (legacy path):
 *   receipt_number|timestamp|total|currency|vat_hash|payment_hash
 *   - Genesis receipt: Uses terminal-specific 256-bit random seed
 *   - Chained receipts: previous_hash is the hash of the preceding receipt
 *
 * Compliance: NF525 (France), extensible for other jurisdictions.
 */
final class ReceiptHashService
{
    private const ALGORITHM = 'sha256';

    private const SEPARATOR = '|';

    public function __construct(
        private readonly FiscalHashService $fiscalHashService,
        private readonly FiscalIntegrityProvider $integrityProvider,
        private readonly DatabaseManager $databaseManager,
        private readonly V3ReceiptHashComputer $v3Computer,
    ) {}

    /**
     * The connection resolved at CALL time.
     *
     * A `ConnectionInterface` captured in the CONSTRUCTOR is pinned to the
     * central connection for the life of the object: `db.connection` is a bind
     * returning `$app['db']->connection()`, and `tenancy()->initialize()` only
     * purges the `tenant` connection and re-points `database.default` — an
     * already-handed-out `Connection` object keeps its own PDO. This service is
     * constructor-injected into `pos:verify-chains`, which the console kernel
     * builds BEFORE `forEachTenant()` binds any tenant, so the authoritative
     * `fiscal_events` arm below used to query CENTRAL under db-per-tenant
     * (42P01 in production; the wrong tenant's answer in compat mode).
     *
     * Same template as `VerifyEventChainCommand::db()` /
     * `EnqueueResolvedEventProjectionsCommand::db()` (6c07d2730). Regression:
     * `tests/Feature/POS/VerifyPosChainCommandDbPerTenantTest.php`.
     */
    private function db(): ConnectionInterface
    {
        return $this->databaseManager->connection();
    }

    /**
     * Calculate fiscal hash for a POS receipt
     *
     * @param  Receipt  $receipt  The receipt to hash
     * @param  string|null  $previousHash  Previous receipt hash from terminal
     * @return string SHA-256 hash (64 characters hex)
     */
    public function calculateHash(Receipt $receipt, ?string $previousHash): string
    {
        $input = $this->serializeForHashing($receipt);

        return $this->fiscalHashService->calculateHash(
            input: $input,
            previousHash: $previousHash,
            genesisSeed: $receipt->terminal->genesis_seed
        );
    }

    /**
     * Serialize receipt data for hashing (NF525 compliant)
     *
     * Format: receipt_number|posted_at|total|currency|vat_hash|payment_hash
     *
     * @param  Receipt  $receipt  The receipt to serialize
     * @return string Pipe-separated serialized data
     */
    public function serializeForHashing(Receipt $receipt): string
    {
        return implode(self::SEPARATOR, [
            $receipt->receipt_number,
            $receipt->posted_at->toIso8601String(),
            $receipt->total,
            $receipt->currency,
            $receipt->vat_breakdown_hash,
            $receipt->payment_methods_hash,
        ]);
    }

    /**
     * Calculate hash for VAT breakdown (for vat_breakdown_hash column)
     *
     * Hashes the VAT details to ensure tax calculation integrity.
     *
     * @param  array<array{tax_rate: float|string, net_amount: float|string, vat_amount: float|string, gross_amount: float|string}>  $vatDetails
     * @return string SHA-256 hash of VAT breakdown
     */
    public function hashVATBreakdown(array $vatDetails): string
    {
        // Sort by tax rate for consistent hashing
        usort($vatDetails, function ($a, $b) {
            /** @var numeric-string $rateA */
            $rateA = (string) $a['tax_rate'];
            /** @var numeric-string $rateB */
            $rateB = (string) $b['tax_rate'];

            return bccomp($rateA, $rateB, 2);
        });

        $breakdown = collect($vatDetails)
            ->map(fn ($detail) => implode(':', [
                $detail['tax_rate'],
                $detail['net_amount'],
                $detail['vat_amount'],
                $detail['gross_amount'],
            ]))
            ->join(';');

        return hash(self::ALGORITHM, $breakdown);
    }

    /**
     * Calculate hash for payment methods (for payment_methods_hash column)
     *
     * Hashes the payment breakdown to ensure payment integrity.
     *
     * @param  array<array{payment_type: string, amount: float|string}>  $payments
     * @return string SHA-256 hash of payment methods
     */
    public function hashPaymentMethods(array $payments): string
    {
        // Sort by payment type for consistent hashing
        usort($payments, fn ($a, $b) => strcmp($a['payment_type'], $b['payment_type']));

        $paymentString = collect($payments)
            ->map(fn ($payment) => implode(':', [
                $payment['payment_type'],
                $payment['amount'],
            ]))
            ->join(';');

        return hash(self::ALGORITHM, $paymentString);
    }

    /**
     * Verify hash chain integrity for a terminal.
     *
     * **Phase 1 §5.0 D1 / §13 rebuild (Task 30).** The verifier now has
     * two arms:
     *
     *   - **Authoritative arm — `fiscal_events`.** Walks every fiscal-events
     *     row for the terminal in `sequence_number` order, re-hashes the
     *     stored `canonical_bytes`, and asserts the recomputed digest
     *     matches `current_hash`. Also asserts `previous_hash` links to
     *     the prior row's `current_hash` (first row → terminal
     *     `genesis_seed`). Tampering with the projection-row mirror
     *     fields (e.g. `pos_receipts.total`) does NOT trigger a break —
     *     the chain truth lives on the canonical bytes.
     *
     *   - **Legacy arm — pre-Task-21 pos_receipts rows.** Rows where
     *     `fiscal_event_id IS NULL` are pre-Phase-1 server-authored
     *     receipts whose `fiscal_hash` was computed via the legacy
     *     `serializeForHashing()` recomputation. Verified through the
     *     original path — the legacy verifier survives until those rows
     *     age out (Task 25 / Task 31 retirement).
     *
     * If BOTH arms are empty the chain is vacuously valid. If either arm
     * detects a break the verifier returns false; both arms are
     * independent so a clean fiscal_events chain alongside a tampered
     * legacy row still fails.
     *
     * @return bool True if both arms verify clean, false on any break.
     */
    public function verifyTerminalChain(Terminal $terminal): bool
    {
        if (! $this->verifyTerminalChainFiscalArm($terminal)) {
            return false;
        }

        return $this->verifyLegacyArm($terminal);
    }

    /**
     * Authoritative arm — walk `fiscal_events` for the terminal, re-hash
     * `canonical_bytes`, assert chain linkage. Mirrors the
     * `fiscal:verify-event-chain` walk pattern (Task 31) so both verifiers
     * detect the same incidents.
     *
     * **Public surface (Task 30 round-2 BLOCKER closure).** Exposed to
     * `Nf525DataProvider::verifyReceiptChain` so the LIVE NF525
     * verify-chains endpoint uses the rebuilt canonical-bytes verifier
     * for fiscal_events-backed rows; the legacy DTO surface still
     * reports per-sequence diagnostics for legacy rows separately.
     *
     * On failure: emits `Log::error('chain_verification_failed', ...)`
     * with structured context (terminal_id, failed_fiscal_event_id,
     * failed_sequence_number, expected_hash, actual_hash, failure_mode)
     * BEFORE returning false. Closes Codex T30-P2 (P2) — bare bool
     * return preserved (3 caller back-compat) plus structured
     * side-channel diagnostic for auditors / operators.
     */
    public function verifyTerminalChainFiscalArm(Terminal $terminal): bool
    {
        $rows = $this->db()->table('fiscal_events')
            ->where('terminal_id', $terminal->id)
            ->orderBy('sequence_number')
            ->get(['id', 'sequence_number', 'canonical_bytes', 'previous_hash', 'current_hash']);

        if ($rows->isEmpty()) {
            return true;
        }

        $expectedPrevious = $terminal->genesis_seed;

        foreach ($rows as $row) {
            // (1) Re-hash check — canonical_bytes is the chain truth.
            $canonicalBytes = $this->stringifyCanonicalBytes($row->canonical_bytes);
            $rehashed = $this->integrityProvider->computeHash($canonicalBytes);
            $storedCurrentHash = (string) $row->current_hash;
            if (! hash_equals(strtolower($rehashed), strtolower($storedCurrentHash))) {
                $this->logChainFailure(
                    terminal: $terminal,
                    row: $row,
                    expectedHash: strtolower($rehashed),
                    actualHash: strtolower($storedCurrentHash),
                    failureMode: 'hash_mismatch',
                );

                return false;
            }

            // (2) Link check — previous_hash must equal the expected
            // chain head (terminal genesis_seed on row 1; prior row's
            // current_hash thereafter). $expectedPrevious is seeded from
            // the terminal's genesis_seed (validated at registration to
            // be a non-empty hex string) and then chained off the prior
            // row's current_hash — both come from CHECK-constrained
            // columns.
            //
            // Opus P3-2 (Task 30 round-2): defensive 64-char length guard
            // on the expected-previous string before hash_equals. A
            // length-0 expected (missing genesis_seed) still fails
            // hash_equals against the row's 64-hex previous_hash, but
            // surfacing 'genesis_seed_missing' here gives operators a
            // crisp diagnostic instead of an opaque "hash mismatch".
            $expectedPreviousStr = (string) $expectedPrevious;
            if (strlen($expectedPreviousStr) !== 64) {
                $this->logChainFailure(
                    terminal: $terminal,
                    row: $row,
                    expectedHash: $expectedPreviousStr,
                    actualHash: (string) $row->previous_hash,
                    failureMode: 'genesis_seed_or_link_length_invalid',
                );

                return false;
            }

            $storedPreviousHash = (string) $row->previous_hash;
            if (! hash_equals(strtolower($expectedPreviousStr), strtolower($storedPreviousHash))) {
                $this->logChainFailure(
                    terminal: $terminal,
                    row: $row,
                    expectedHash: strtolower($expectedPreviousStr),
                    actualHash: strtolower($storedPreviousHash),
                    failureMode: 'linkage_broken',
                );

                return false;
            }

            $expectedPrevious = $storedCurrentHash;
        }

        return true;
    }

    /**
     * Closes Codex T30-P2 (P2). Emits a single structured Log::error
     * carrying terminal_id + fiscal_event_id + sequence_number +
     * expected/actual hashes + failure mode. Public bool contract is
     * preserved (3 callers — VerifyPosChainCommand, ReportController,
     * Nf525DataProvider — see no change in shape).
     */
    private function logChainFailure(
        Terminal $terminal,
        \stdClass $row,
        string $expectedHash,
        string $actualHash,
        string $failureMode,
    ): void {
        Log::error('chain_verification_failed', [
            'terminal_id' => $terminal->id,
            'failed_fiscal_event_id' => (string) $row->id,
            'failed_sequence_number' => (int) $row->sequence_number,
            'expected_hash' => $expectedHash,
            'actual_hash' => $actualHash,
            'failure_mode' => $failureMode,
        ]);
    }

    /**
     * Legacy arm — verifies pos_receipts rows that pre-date the
     * fiscal-events rebuild. Pinned by Task 21 R2: `fiscal_event_id IS
     * NULL` partitions pre-Phase-1 rows from projection rows.
     *
     * **v3-refund-chain-integration spec §6 — per-row algorithm dispatch.**
     * `ReceiptFinalizationService::finalize()` seals BOTH schema_version=2
     * (legacy pipe-separated `calculateHash()`) AND schema_version=3
     * (`V3ReceiptHashComputer`'s canonical-JSON hash) receipts WITHOUT
     * setting `fiscal_event_id` — so this "legacy" partition is not
     * uniformly pipe-format; it needs the per-row `sealed_hash_algorithm`
     * discriminator to pick the matching re-verification arm. §6.3's
     * NULL-handling: before a terminal's backfill completes, a NULL
     * discriminator means `legacy_pipe_v1` (every row that predates the
     * column is, by construction, legacy-pipe-sealed — this IS today's
     * unconditional behavior, preserved exactly); after backfill
     * completion, a remaining NULL is a genuine anomaly and fails closed.
     */
    private function verifyLegacyArm(Terminal $terminal): bool
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->get();

        if ($receipts->isEmpty()) {
            return true;
        }

        $previousHash = null;

        foreach ($receipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return false;
            }

            $algorithm = $this->resolveSealedHashAlgorithm($receipt, $terminal);
            if ($algorithm === null) {
                // §6.3: post-backfill-completion NULL is a genuine anomaly —
                // fail closed rather than guess a format.
                return false;
            }

            $expectedHash = $this->computeHashForAlgorithm($receipt, $algorithm, $previousHash);

            if ($expectedHash !== $receipt->fiscal_hash) {
                return false;
            }

            $previousHash = $receipt->fiscal_hash;
        }

        // Legacy `last_hash` is only a valid anchor when the terminal has
        // legacy rows (the authoritative chain head lives on the
        // fiscal_events row's `current_hash` and is checked by the
        // authoritative arm above).
        if ($previousHash !== $terminal->last_hash && $terminal->last_hash !== null) {
            // last_hash being non-null but mismatched is a legacy chain
            // tail mismatch.
            return false;
        }

        return true;
    }

    /**
     * §6.3's NULL-handling gate. NULL discriminator resolves to
     * `legacy_pipe_v1` before the terminal's backfill completion is
     * recorded (every pre-migration row is, by construction, legacy-pipe-
     * sealed); resolves to `null` (genuine anomaly, caller fails closed)
     * once backfill completion is recorded and a row is still unset.
     *
     * Public: shared with `Nf525DataProvider::verifyReceiptChain()`'s
     * identical per-row algorithm dispatch (spec §6/§17) so the §6.3 gate
     * has exactly one implementation, not two copies that could drift.
     */
    public function resolveSealedHashAlgorithm(Receipt $receipt, Terminal $terminal): ?SealedHashAlgorithm
    {
        if ($receipt->sealed_hash_algorithm !== null) {
            return SealedHashAlgorithm::from($receipt->sealed_hash_algorithm);
        }

        return $terminal->sealed_hash_algorithm_backfill_completed_at === null
            ? SealedHashAlgorithm::LegacyPipeV1
            : null;
    }

    /**
     * Recompute the expected `fiscal_hash` for the given algorithm — the
     * single dispatch point both {@see verifyLegacyArm()} and
     * `Nf525DataProvider::verifyReceiptChain()`'s identical repair share
     * (spec §6/§17), so a third arm added in the future has exactly one
     * place to extend.
     */
    public function computeHashForAlgorithm(Receipt $receipt, SealedHashAlgorithm $algorithm, ?string $previousHash): string
    {
        return match ($algorithm) {
            SealedHashAlgorithm::LegacyPipeV1 => $this->calculateHash($receipt, $previousHash),
            SealedHashAlgorithm::CanonicalJsonV3 => $this->v3Computer->compute($receipt),
        };
    }

    /**
     * `canonical_bytes` is BYTEA on PG and BLOB on SQLite. Coerce the
     * driver-dependent shape to a string for the hash primitive — same
     * helper shape as `VerifyEventChainCommand::stringifyCanonicalBytes`.
     */
    private function stringifyCanonicalBytes(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }

    /**
     * Verify a single receipt's hash.
     *
     * **Phase 1 §5.0 D1 rebuild.** Projection rows
     * (`fiscal_event_id IS NOT NULL`) verify by re-hashing the linked
     * `fiscal_events.canonical_bytes` and comparing to the stored
     * `fiscal_hash` mirror — the canonical bytes are the chain truth, not
     * the model fields. Legacy rows (`fiscal_event_id IS NULL`) verify
     * via the original NF525 pipe-separated recomputation.
     *
     * @return bool True if hash matches the appropriate authoritative source.
     */
    public function verifyHash(Receipt $receipt): bool
    {
        if ($receipt->fiscal_event_id !== null) {
            $canonicalBytes = $this->db()->table('fiscal_events')
                ->where('id', $receipt->fiscal_event_id)
                ->value('canonical_bytes');

            if ($canonicalBytes === null) {
                // FK is set but the row vanished — defensive: cannot verify.
                return false;
            }

            $rehashed = $this->integrityProvider->computeHash(
                $this->stringifyCanonicalBytes($canonicalBytes),
            );

            // Receipt::$fiscal_hash is non-null after creation (per the
            // Receipt model docblock — fiscal_hash is set during finalize).
            return hash_equals(strtolower($rehashed), strtolower($receipt->fiscal_hash));
        }

        $input = $this->serializeForHashing($receipt);

        return $this->fiscalHashService->verifyHash(
            input: $input,
            previousHash: $receipt->previous_hash,
            storedHash: $receipt->fiscal_hash,
            genesisSeed: $receipt->terminal->genesis_seed,
        );
    }

    /**
     * Verify VAT breakdown hash
     *
     * @param  Receipt  $receipt  The receipt with VAT details
     * @return bool True if VAT hash matches stored value
     */
    public function verifyVATBreakdownHash(Receipt $receipt): bool
    {
        $vatDetails = $receipt->vatDetails()
            ->get()
            ->map(fn ($detail) => [
                'tax_rate' => $detail->tax_rate,
                'net_amount' => $detail->net_amount,
                'vat_amount' => $detail->vat_amount,
                'gross_amount' => $detail->gross_amount,
            ])
            ->toArray();

        $calculatedHash = $this->hashVATBreakdown($vatDetails);

        return $calculatedHash === $receipt->vat_breakdown_hash;
    }

    /**
     * Verify payment methods hash
     *
     * @param  Receipt  $receipt  The receipt with payment details
     * @return bool True if payment hash matches stored value
     */
    public function verifyPaymentMethodsHash(Receipt $receipt): bool
    {
        $payments = $receipt->payments()
            ->get()
            ->map(fn ($payment) => [
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
            ])
            ->toArray();

        $calculatedHash = $this->hashPaymentMethods($payments);

        return $calculatedHash === $receipt->payment_methods_hash;
    }

    /**
     * Get the algorithm used for hashing
     */
    public function getAlgorithm(): string
    {
        return self::ALGORITHM;
    }
}
