<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Domain\DTOs\ReceiptChainArmVerificationResult;
use App\Modules\POS\Domain\Enums\FiscalStatus;
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
     * three arms:
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
     *   - **Projected mirror arm.** Every receipt linked through
     *     `fiscal_event_id` must mirror the referenced event's
     *     `current_hash`. This is deliberately separate from the
     *     authoritative canonical-bytes arm: one proves the event chain,
     *     the other proves the projection still points at the same digest.
     *
     * If all three arms are empty the chain is vacuously valid. If any arm
     * detects a break the verifier returns false; the arms are independent
     * so a clean fiscal_events chain alongside a tampered
     * legacy row still fails.
     *
     * @return bool True if all arms verify clean, false on any break.
     */
    public function verifyTerminalChain(Terminal $terminal): bool
    {
        foreach ($this->verifyTerminalChainArms($terminal) as $arm) {
            if (! $arm->isValid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify and diagnose each receipt-chain arm independently.
     *
     * The fixed keys are consumed by `pos:verify-chains` so operators see
     * accurate receipt coverage and a breakpoint for the arm that failed.
     * The fiscal arm's `inspectedCount` remains the full terminal event walk;
     * its displayed `count` is only events linked from projected receipts.
     *
     * @return array{
     *     fiscal_events: ReceiptChainArmVerificationResult,
     *     projected_mirror: ReceiptChainArmVerificationResult,
     *     legacy: ReceiptChainArmVerificationResult
     * }
     */
    public function verifyTerminalChainArms(Terminal $terminal): array
    {
        return [
            'fiscal_events' => $this->inspectFiscalEventsArm($terminal),
            'projected_mirror' => $this->inspectProjectedMirrorArm($terminal),
            'legacy' => $this->verifyLegacyArm($terminal),
        ];
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
        return $this->inspectFiscalEventsArm($terminal)->isValid;
    }

    private function inspectFiscalEventsArm(Terminal $terminal): ReceiptChainArmVerificationResult
    {
        $rows = $this->db()->table('fiscal_events as events')
            ->leftJoin('pos_receipts as receipts', 'receipts.fiscal_event_id', '=', 'events.id')
            ->where('events.terminal_id', $terminal->id)
            ->orderBy('events.company_id')
            ->orderBy('events.chain_context')
            ->orderBy('events.sequence_number')
            ->get([
                'events.id',
                'events.company_id',
                'events.chain_context',
                'events.sequence_number',
                'events.canonical_bytes',
                'events.previous_hash',
                'events.current_hash',
                'receipts.id as projected_receipt_id',
                'receipts.terminal_id as receipt_terminal_id',
            ]);

        if ($rows->isEmpty()) {
            return new ReceiptChainArmVerificationResult(true, 0);
        }

        $receiptCoverageCount = $rows
            ->filter(fn (\stdClass $row): bool => $row->projected_receipt_id !== null
                && (string) $row->receipt_terminal_id === (string) $terminal->id)
            ->count();
        $inspectedCount = $rows->count();

        // ONE CHAIN PER (company_id, chain_context) — STOP C ruling,
        // docs/handoff/reviews/es-wave-a0/ORCHESTRATOR-RULING-2026-08-19-m2-stop-c.md.
        //
        // `sequence_number` is unique per
        // (tenant_id, company_id, terminal_id, chain_context, sequence_number)
        // — 2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31 —
        // so every context restarts at 1 and anchors at the terminal
        // genesis_seed. Walking one flattened `sequence_number` stream for
        // the whole terminal carried the operational head into the
        // z_session chain and reported a linkage break on the NORMAL v3
        // shape (every v3 terminal has both contexts). This is the same
        // per-context head resolution `OutboxIngestor` uses when it APPENDS
        // (OutboxIngestor.php:172-179) and `ZReportHashService` uses when it
        // VERIFIES (ZReportHashService.php:262-291).
        /** @var array<string, list<\stdClass>> $streams */
        $streams = [];
        foreach ($rows as $row) {
            $streams[(string) $row->company_id."\0".(string) $row->chain_context][] = $row;
        }

        foreach ($streams as $streamRows) {
            $failure = $this->inspectFiscalEventStream(
                terminal: $terminal,
                rows: $streamRows,
                receiptCoverageCount: $receiptCoverageCount,
                inspectedCount: $inspectedCount,
            );

            if ($failure !== null) {
                return $failure;
            }
        }

        return new ReceiptChainArmVerificationResult(
            isValid: true,
            count: $receiptCoverageCount,
            inspectedCount: $inspectedCount,
        );
    }

    /**
     * Walk ONE (company_id, chain_context) stream from the terminal
     * genesis_seed. Returns the failing arm result, or null when the stream
     * verifies clean.
     *
     * @param  list<\stdClass>  $rows  Rows of a single stream, sequence-ordered.
     */
    private function inspectFiscalEventStream(
        Terminal $terminal,
        array $rows,
        int $receiptCoverageCount,
        int $inspectedCount,
    ): ?ReceiptChainArmVerificationResult {
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

                return new ReceiptChainArmVerificationResult(
                    isValid: false,
                    count: $receiptCoverageCount,
                    breakPoint: $this->fiscalEventBreakPoint($row, 'hash'),
                    inspectedCount: $inspectedCount,
                );
            }

            // (2) Link check — previous_hash must equal the expected
            // chain head of THIS context (terminal genesis_seed on the
            // context's first row; prior row's current_hash thereafter).
            // $expectedPrevious is seeded from the terminal's genesis_seed
            // (validated at registration to be a non-empty hex string) and
            // then chained off the prior row's current_hash — both come
            // from CHECK-constrained columns.
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

                return new ReceiptChainArmVerificationResult(
                    isValid: false,
                    count: $receiptCoverageCount,
                    breakPoint: $this->fiscalEventBreakPoint($row, 'link length'),
                    inspectedCount: $inspectedCount,
                );
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

                return new ReceiptChainArmVerificationResult(
                    isValid: false,
                    count: $receiptCoverageCount,
                    breakPoint: $this->fiscalEventBreakPoint($row, 'link'),
                    inspectedCount: $inspectedCount,
                );
            }

            $expectedPrevious = $storedCurrentHash;
        }

        return null;
    }

    /**
     * A break coordinate an operator can act on. `sequence_number` alone is
     * ambiguous once contexts are partitioned — each restarts at 1 — so the
     * context is part of the coordinate.
     */
    private function fiscalEventBreakPoint(\stdClass $row, string $check): string
    {
        return sprintf(
            'Event %s (context %s, sequence #%d, %s)',
            (string) $row->id,
            (string) $row->chain_context,
            (int) $row->sequence_number,
            $check,
        );
    }

    /**
     * Cross-layer projection control: the receipt's stored fiscal hash must
     * equal the current hash on the exact event referenced by fiscal_event_id.
     * A left join is intentional so a missing referenced row fails closed.
     */
    private function inspectProjectedMirrorArm(Terminal $terminal): ReceiptChainArmVerificationResult
    {
        $rows = $this->db()->table('pos_receipts as receipts')
            ->leftJoin('fiscal_events as events', 'events.id', '=', 'receipts.fiscal_event_id')
            ->where('receipts.terminal_id', $terminal->id)
            ->whereNotNull('receipts.fiscal_event_id')
            ->orderBy('receipts.chain_sequence')
            ->get([
                'receipts.id as receipt_id',
                'receipts.fiscal_event_id',
                'receipts.fiscal_hash as receipt_hash',
                'events.current_hash as event_hash',
            ]);

        foreach ($rows as $row) {
            $receiptId = (string) $row->receipt_id;
            $eventId = (string) $row->fiscal_event_id;
            $receiptHash = $row->receipt_hash;
            $eventHash = $row->event_hash;

            if (! is_string($receiptHash) || $receiptHash === '') {
                return $this->projectedMirrorFailure(
                    terminal: $terminal,
                    count: $rows->count(),
                    receiptId: $receiptId,
                    eventId: $eventId,
                    failureMode: 'missing_receipt_hash',
                    detail: 'missing receipt fiscal_hash',
                );
            }

            if (! is_string($eventHash) || $eventHash === '') {
                return $this->projectedMirrorFailure(
                    terminal: $terminal,
                    count: $rows->count(),
                    receiptId: $receiptId,
                    eventId: $eventId,
                    failureMode: 'missing_referenced_event_hash',
                    detail: 'missing event/current_hash',
                );
            }

            if (! hash_equals(strtolower($eventHash), strtolower($receiptHash))) {
                return $this->projectedMirrorFailure(
                    terminal: $terminal,
                    count: $rows->count(),
                    receiptId: $receiptId,
                    eventId: $eventId,
                    failureMode: 'hash_mismatch',
                    detail: 'hash mirror',
                );
            }
        }

        return new ReceiptChainArmVerificationResult(true, $rows->count());
    }

    private function projectedMirrorFailure(
        Terminal $terminal,
        int $count,
        string $receiptId,
        string $eventId,
        string $failureMode,
        string $detail,
    ): ReceiptChainArmVerificationResult {
        Log::error('receipt_chain_mirror_verification_failed', [
            'terminal_id' => $terminal->id,
            'receipt_id' => $receiptId,
            'fiscal_event_id' => $eventId,
            'failure_mode' => $failureMode,
        ]);

        return new ReceiptChainArmVerificationResult(
            isValid: false,
            count: $count,
            breakPoint: sprintf('Receipt %s -> Event %s (%s)', $receiptId, $eventId, $detail),
        );
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
    private function verifyLegacyArm(Terminal $terminal): ReceiptChainArmVerificationResult
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            // PENDING-SEAL EXCLUSION (M2-round1 finding 1). An in-flight
            // receipt is not yet sealed: no chain_sequence, no previous_hash,
            // no fiscal_hash. It is not part of the chain and must stay
            // invisible to the verifier. `pos:verify-chains` used to carry
            // this predicate itself; per-arm reporting removed the
            // command-level pre-count, so the arm that actually walks legacy
            // rows owns it now — otherwise any terminal holding one open sale
            // (routine in production) reports a false chain break and E-7
            // evidence runs go false-red.
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->get();

        if ($receipts->isEmpty()) {
            return new ReceiptChainArmVerificationResult(true, 0);
        }

        $previousHash = null;

        foreach ($receipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return new ReceiptChainArmVerificationResult(
                    false,
                    $receipts->count(),
                    $this->legacyBreakPoint($receipt, 'link'),
                );
            }

            $algorithm = $this->resolveSealedHashAlgorithm($receipt, $terminal);
            if ($algorithm === null) {
                // §6.3: post-backfill-completion NULL is a genuine anomaly —
                // fail closed rather than guess a format.
                return new ReceiptChainArmVerificationResult(
                    false,
                    $receipts->count(),
                    $this->legacyBreakPoint($receipt, 'sealed algorithm'),
                );
            }

            $expectedHash = $this->computeHashForAlgorithm($receipt, $algorithm, $previousHash);

            if ($expectedHash !== $receipt->fiscal_hash) {
                return new ReceiptChainArmVerificationResult(
                    false,
                    $receipts->count(),
                    $this->legacyBreakPoint($receipt, 'hash'),
                );
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
            return new ReceiptChainArmVerificationResult(
                false,
                $receipts->count(),
                'Terminal last_hash mismatch',
            );
        }

        return new ReceiptChainArmVerificationResult(true, $receipts->count());
    }

    /**
     * A legacy break coordinate that stays usable when `chain_sequence` is
     * NULL. `sprintf('%d', null)` prints `Sequence #0` — a coordinate that
     * points at no row and sends an operator hunting for a receipt that does
     * not exist (M2-round1 finding 1, sub-defect).
     */
    private function legacyBreakPoint(Receipt $receipt, string $check): string
    {
        if ($receipt->chain_sequence === null) {
            return sprintf('Receipt %s (unsequenced, %s)', (string) $receipt->id, $check);
        }

        return sprintf('Sequence #%d (%s)', $receipt->chain_sequence, $check);
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
