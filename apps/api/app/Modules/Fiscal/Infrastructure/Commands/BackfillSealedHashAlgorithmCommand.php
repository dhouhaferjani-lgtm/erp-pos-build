<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Domain\Enums\SealedHashAlgorithm;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v3-refund-chain-integration spec §6 — one-time backfill of
 * `pos_receipts.sealed_hash_algorithm` for legacy (`fiscal_event_id IS
 * NULL`) rows sealed before this column existed.
 *
 * Per terminal, walks its legacy receipts in `chain_sequence` order (same
 * chain-continuity walk `ReceiptHashService::verifyLegacyArm()` uses) and,
 * for each row still missing the discriminator, tries BOTH candidate
 * pipelines against the row's stored `fiscal_hash`:
 *   - `legacy_pipe_v1` — `ReceiptHashService::calculateHash()`'s pipe-
 *     separated recomputation, chained off the running previous hash.
 *   - `canonical_json_v3` — `V3ReceiptHashComputer::compute()`, reading the
 *     row's own stored `previous_hash`.
 *
 * §6's stated failure discipline: a row where NEITHER algorithm matches, or
 * — a genuine tamper/corruption signal — BOTH match (impossible for a
 * well-formed row, logged as a distinct anomaly), is logged and skipped,
 * never guessed. Once every legacy row for a terminal has been visited AND
 * NONE were skipped, the terminal's
 * `sealed_hash_algorithm_backfill_completed_at` is stamped — §6.3's gate
 * for the verifier's NULL-handling.
 *
 * **review round-2 IMPORTANT 13 (a) — chain off each row's OWN STORED
 * `previous_hash`.** `ReceiptHashService::verifyLegacyArm()` walks a
 * `is_voided=false, is_training=false`-FILTERED query, so its own running
 * `$previousHash` variable only ever advances across NON-voided,
 * NON-training rows. This command's walk previously tracked a running
 * `$previousHash` across an UNFILTERED query — every legacy row for the
 * terminal, voided/training included — which can diverge from the
 * filtered chain the verifier (and, historically, whatever process
 * originally sealed these `legacy_pipe_v1` rows) actually used, causing a
 * false skip (or worse, a false match) whenever a voided/training row sits
 * between two rows being backfilled. Reading each row's OWN
 * `previous_hash` column directly — the value from when it was originally
 * sealed — sidesteps reconstructing any filtered walk at all; it is
 * correct by construction for whatever chaining rule actually applied at
 * signing time. `SealedHashAlgorithm::CanonicalJsonV3`'s own candidate
 * computation already read the stored column internally
 * ({@see V3ReceiptHashComputer::buildInput()})
 * — only the `LegacyPipeV1` arm was exposed to this mismatch.
 *
 * **review round-2 IMPORTANT 13 (b) — withhold the completion stamp when
 * any row was skipped.** Stamping `sealed_hash_algorithm_backfill_completed_at`
 * flips `resolveSealedHashAlgorithm()`'s NULL-handling from "assume
 * legacy_pipe_v1" (pre-completion, §6.3) to "genuine anomaly, fail
 * closed" (post-completion) for any row STILL null. Stamping completion
 * while a row remains unresolved would immediately start failing
 * verification for it with no operator warning. `--force` opts in
 * explicitly once an operator has reviewed the `sealed_hash_algorithm_backfill_skipped`
 * log entries and is satisfied treating any remaining nulls as anomalies.
 *
 * Idempotent: re-running only touches rows still `sealed_hash_algorithm IS
 * NULL`; the one-time NULL->value trigger transition
 * (`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`)
 * rejects a second write on an already-set row at the DB layer regardless.
 */
final class BackfillSealedHashAlgorithmCommand extends TenantScopedCommand
{
    /** @var string */
    protected $signature = 'fiscal:backfill-sealed-hash-algorithm
        {--tenant= : restrict tenant iteration to one tenant id}
        {--terminal= : restrict to one pos_terminals.id}
        {--dry-run : report matching rows without writing anything}
        {--force : stamp backfill-complete even when rows were skipped (review round-2 IMPORTANT 13b)}';

    /** @var string */
    protected $description = 'Backfill pos_receipts.sealed_hash_algorithm for legacy (fiscal_event_id IS NULL) rows and stamp per-terminal backfill completion.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $tenantFilter = $this->stringOption('tenant');
        $terminalFilter = $this->stringOption('terminal');
        $dryRun = $this->option('dry-run') === true;
        $force = $this->option('force') === true;

        $totalWritten = 0;
        $totalSkipped = 0;
        $totalTerminalsCompleted = 0;
        $totalTerminalsWithheld = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $tenantFilter,
            $terminalFilter,
            $dryRun,
            $force,
            &$totalWritten,
            &$totalSkipped,
            &$totalTerminalsCompleted,
            &$totalTerminalsWithheld,
        ): int {
            if ($tenantFilter !== null && $tenant->id !== $tenantFilter) {
                return self::SUCCESS;
            }

            $terminals = Terminal::query()->where('tenant_id', $tenant->id)->get();

            foreach ($terminals as $terminal) {
                if ($terminalFilter !== null && $terminal->id !== $terminalFilter) {
                    continue;
                }

                [$written, $skipped, $stamped] = $this->backfillTerminal($terminal, $dryRun, $force);
                $totalWritten += $written;
                $totalSkipped += $skipped;

                if (! $dryRun) {
                    if ($stamped) {
                        $totalTerminalsCompleted++;
                    } else {
                        $totalTerminalsWithheld++;
                    }
                }
            }

            return self::SUCCESS;
        });

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: %d legacy row(s) would be backfilled; %d would be logged-and-skipped.',
                $totalWritten,
                $totalSkipped,
            ));

            return $exit;
        }

        $this->info(sprintf(
            'Backfilled %d legacy pos_receipts row(s); %d logged-and-skipped; %d terminal(s) marked backfill-complete; %d terminal(s) withheld (skips present, re-run with --force to stamp anyway).',
            $totalWritten,
            $totalSkipped,
            $totalTerminalsCompleted,
            $totalTerminalsWithheld,
        ));

        return $exit;
    }

    /**
     * @return array{int, int, bool} [writtenCount, skippedCount, wasStamped]
     */
    private function backfillTerminal(Terminal $terminal, bool $dryRun, bool $force): array
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->orderBy('chain_sequence')
            ->get();

        $written = 0;
        $skipped = 0;

        foreach ($receipts as $receipt) {
            $receipt->setRelation('terminal', $terminal);

            if ($receipt->sealed_hash_algorithm !== null) {
                // Already backfilled (or freshly sealed post-feature) — no
                // rewrite needed.
                continue;
            }

            // review round-2 IMPORTANT 13(a) — the row's OWN stored
            // previous_hash, not a running variable reconstructed across
            // an is_voided/is_training-UNFILTERED walk (see class docblock).
            // NULL (the first receipt in the chain) must stay NULL, never
            // cast to an empty string — calculateHash()/computeHashForAlgorithm()
            // fall back to the terminal's genesis_seed only when this is a
            // genuine null, not an empty-string previous hash.
            /** @var string|null $previousHash */
            $previousHash = $receipt->previous_hash;

            $legacyCandidate = $this->receiptHashService->computeHashForAlgorithm(
                $receipt,
                SealedHashAlgorithm::LegacyPipeV1,
                $previousHash,
            );
            $v3Candidate = $this->receiptHashService->computeHashForAlgorithm(
                $receipt,
                SealedHashAlgorithm::CanonicalJsonV3,
                $previousHash,
            );

            $legacyMatches = $legacyCandidate === $receipt->fiscal_hash;
            $v3Matches = $v3Candidate === $receipt->fiscal_hash;

            if ($legacyMatches === $v3Matches) {
                // Neither matched, or (impossible for a well-formed row,
                // logged as its own anomaly class) both did — never guess.
                Log::warning('sealed_hash_algorithm_backfill_skipped', [
                    'receipt_id' => $receipt->id,
                    'terminal_id' => $terminal->id,
                    'reason' => $legacyMatches ? 'both_algorithms_matched' : 'neither_algorithm_matched',
                ]);
                $skipped++;

                continue;
            }

            $algorithm = $legacyMatches ? SealedHashAlgorithm::LegacyPipeV1 : SealedHashAlgorithm::CanonicalJsonV3;

            if (! $dryRun) {
                $receipt->sealed_hash_algorithm = $algorithm->value;
                $receipt->save();
            }

            $written++;
        }

        // review round-2 IMPORTANT 13(b) — withhold the completion stamp
        // when any row was skipped, unless --force. See class docblock.
        $shouldStamp = $skipped === 0 || $force;

        if (! $dryRun && $shouldStamp) {
            DB::table('pos_terminals')
                ->where('id', $terminal->id)
                ->update(['sealed_hash_algorithm_backfill_completed_at' => now()]);
        }

        if (! $dryRun && ! $shouldStamp) {
            Log::warning('sealed_hash_algorithm_backfill_completion_withheld', [
                'terminal_id' => $terminal->id,
                'skipped_count' => $skipped,
            ]);
        }

        return [$written, $skipped, $dryRun ? false : $shouldStamp];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
