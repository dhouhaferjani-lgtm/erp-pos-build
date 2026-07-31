<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
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
 * never guessed. Once every legacy row for a terminal has been visited
 * (regardless of skips), the terminal's
 * `sealed_hash_algorithm_backfill_completed_at` is stamped — §6.3's gate
 * for the verifier's NULL-handling.
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
        {--dry-run : report matching rows without writing anything}';

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

        $totalWritten = 0;
        $totalSkipped = 0;
        $totalTerminalsCompleted = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $tenantFilter,
            $terminalFilter,
            $dryRun,
            &$totalWritten,
            &$totalSkipped,
            &$totalTerminalsCompleted,
        ): int {
            if ($tenantFilter !== null && $tenant->id !== $tenantFilter) {
                return self::SUCCESS;
            }

            $terminals = Terminal::query()->where('tenant_id', $tenant->id)->get();

            foreach ($terminals as $terminal) {
                if ($terminalFilter !== null && $terminal->id !== $terminalFilter) {
                    continue;
                }

                [$written, $skipped] = $this->backfillTerminal($terminal, $dryRun);
                $totalWritten += $written;
                $totalSkipped += $skipped;

                if (! $dryRun) {
                    $totalTerminalsCompleted++;
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
            'Backfilled %d legacy pos_receipts row(s); %d logged-and-skipped; %d terminal(s) marked backfill-complete.',
            $totalWritten,
            $totalSkipped,
            $totalTerminalsCompleted,
        ));

        return $exit;
    }

    /**
     * @return array{int, int} [writtenCount, skippedCount]
     */
    private function backfillTerminal(Terminal $terminal, bool $dryRun): array
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->orderBy('chain_sequence')
            ->get();

        $written = 0;
        $skipped = 0;
        $previousHash = null;

        foreach ($receipts as $receipt) {
            $receipt->setRelation('terminal', $terminal);

            if ($receipt->sealed_hash_algorithm !== null) {
                // Already backfilled (or freshly sealed post-feature) —
                // keep the chain-continuity walk moving without rewriting.
                $previousHash = (string) $receipt->fiscal_hash;

                continue;
            }

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
                $previousHash = (string) $receipt->fiscal_hash;

                continue;
            }

            $algorithm = $legacyMatches ? SealedHashAlgorithm::LegacyPipeV1 : SealedHashAlgorithm::CanonicalJsonV3;

            if (! $dryRun) {
                $receipt->sealed_hash_algorithm = $algorithm->value;
                $receipt->save();
            }

            $written++;
            $previousHash = (string) $receipt->fiscal_hash;
        }

        if (! $dryRun) {
            DB::table('pos_terminals')
                ->where('id', $terminal->id)
                ->update(['sealed_hash_algorithm_backfill_completed_at' => now()]);
        }

        return [$written, $skipped];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
