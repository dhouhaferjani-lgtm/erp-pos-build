<?php

declare(strict_types=1);

namespace App\Modules\POS\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;

/**
 * `pos:verify-chains` — POS receipt + Z-report hash chain verifier (NF525
 * audit). Named as a launch verifier in
 * `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md:131` and step D.2
 * of `docs/qa/2026-05-12-first-tenant-smoke.md`.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter), converted 2026-08-05.
 *
 * The pre-conversion annotation said "the canonical run path is fleet-wide" and
 * backed it with `Terminal::active()->get()` on the console's CENTRAL
 * connection. `pos_terminals` is a TENANT table, so after the 2026-05-28
 * database-per-tenant flip that claim became impossible: a bare run raised
 * 42P01 and a `--company` / `--terminal` run matched nothing. Fleet-wide is
 * still the default, but it is now produced by iterating the tenant directory
 * and opening each tenant's own database.
 *
 * The chain verification LOGIC is untouched — same fiscal-event projection
 * carve-out, same `ReceiptHashService` / `ZReportHashService` calls, same
 * table columns.
 *
 * **Evidence shape.** Each tenant emits its own verdict line and its own
 * results table so an E-7 reviewer can attribute every row; the aggregate exit
 * is non-zero if any tenant reports a break. The run closes with a
 * `TENANT COVERAGE:` block carrying one line for EVERY directory tenant the run
 * touched — `verified` / `FAILED` / `NO-DATA` / `SKIPPED` / `ERRORED` — so the
 * pack shows what was NOT looked at as plainly as what was (2026-08-05 review,
 * B3/C3).
 *
 * **The success banner is a true summary (2026-08-05 review, B2/C2).**
 * `All chains verified successfully.` is the string step D.2 of
 * `docs/qa/2026-05-12-first-tenant-smoke.md` ticks as PASS. It is now
 * unreachable unless the aggregate exit is 0 AND no tenant was skipped by the
 * database probe AND no tenant's run threw. Anything else prints an explicit
 * FAILED / INCOMPLETE banner naming the tenants involved. Exit codes remain
 * 0/1 only — the base's {@see TenantScopedCommand::INVALID} (2) for an unknown
 * `--tenant` is collapsed onto 1 (M2).
 *
 * **Output changed vs the pre-conversion transcripts (M1).** `Verifying %d
 * terminal(s)...` is now `TENANT <id> (<slug>): verifying %d terminal(s)...`,
 * and the historic `Terminal 'X' not found.` line is replaced by the
 * fail-closed `The --terminal / --company filter matched no terminal in any
 * reachable tenant.` Deliberate: per-tenant attribution is the point of the
 * conversion. The D.2 pass string itself is unchanged.
 *
 * **Contract change (fail-closed).** A `--terminal` / `--company` filter that
 * matches nothing in any reachable tenant used to print "not found" / "No
 * active terminals found" and exit **0** — a verifier reporting success for a
 * chain it never looked at. It now exits non-zero. A run with NO filters and
 * genuinely no active terminals anywhere still exits 0, as before.
 */
final class VerifyPosChainCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'pos:verify-chains
        {--tenant= : Tenant UUID to verify (default: every tenant)}
        {--company= : Company UUID to verify}
        {--terminal= : Specific terminal UUID to verify}
        {--type=all : Chain type to verify: all, receipts, z-reports}';

    /**
     * @var string
     */
    protected $description = 'Verify POS receipt and Z-report hash chain integrity, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly ZReportHashService $zReportHashService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['all', 'receipts', 'z-reports'], true)) {
            $this->error("Invalid type '{$type}'. Must be one of: all, receipts, z-reports");

            return self::FAILURE;
        }

        $companyFilter = $this->stringOption('company');
        $terminalFilter = $this->stringOption('terminal');

        $terminalsVerified = 0;
        $hasFailure = false;
        /** @var array<string, string> $verdicts */
        $verdicts = [];

        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use (
                $type,
                $companyFilter,
                $terminalFilter,
                &$terminalsVerified,
                &$hasFailure,
                &$verdicts,
            ): int {
                $terminals = $this->resolveTerminals($tenant, $companyFilter, $terminalFilter);

                if ($terminals->isEmpty()) {
                    // Recorded, not silent: a tenant with nothing to verify is
                    // still coverage an E-7 reviewer has to be able to see.
                    $verdicts[(string) $tenant->id] = 'NO-DATA (no terminal matched this run)';

                    return self::SUCCESS;
                }

                $terminalsVerified += $terminals->count();

                $this->info(sprintf(
                    'TENANT %s (%s): verifying %d terminal(s)...',
                    $tenant->id,
                    $tenant->slug,
                    $terminals->count(),
                ));

                /** @var list<array{terminal_code: string, company: string, chain_type: string, status: string, count: int, break_point: string}> $rows */
                $rows = [];
                $tenantFailed = false;

                foreach ($terminals as $terminal) {
                    $companyName = $terminal->company->name;

                    if ($type === 'all' || $type === 'receipts') {
                        $result = $this->verifyReceiptChain($terminal);
                        $rows[] = [
                            'terminal_code' => $terminal->code,
                            'company' => $companyName,
                            'chain_type' => 'Receipts',
                            'status' => $result['is_valid'] ? "\u{2713}" : "\u{2717}",
                            'count' => $result['count'],
                            'break_point' => $result['break_point'],
                        ];

                        if (! $result['is_valid']) {
                            $tenantFailed = true;
                        }
                    }

                    if ($type === 'all' || $type === 'z-reports') {
                        $result = $this->verifyZReportChain($terminal);
                        $rows[] = [
                            'terminal_code' => $terminal->code,
                            'company' => $companyName,
                            'chain_type' => 'Z-Reports',
                            'status' => $result['is_valid'] ? "\u{2713}" : "\u{2717}",
                            'count' => $result['count'],
                            'break_point' => $result['break_point'],
                        ];

                        if (! $result['is_valid']) {
                            $tenantFailed = true;
                        }
                    }
                }

                $this->table(
                    ['Terminal Code', 'Company', 'Chain Type', 'Status', 'Count', 'Break Point'],
                    $rows,
                );

                if ($tenantFailed) {
                    $hasFailure = true;
                    $verdicts[(string) $tenant->id] = 'FAILED (a chain is broken)';
                    $this->error(sprintf('TENANT %s (%s): chain verification FAILED', $tenant->id, $tenant->slug));

                    return self::FAILURE;
                }

                $verdicts[(string) $tenant->id] = sprintf('verified (%d terminal(s))', $terminals->count());
                $this->info(sprintf('TENANT %s (%s): all chains verified', $tenant->id, $tenant->slug));

                return self::SUCCESS;
            },
        );

        // Coverage FIRST, verdict second — the banner below is only allowed to
        // say "verified" once every directory tenant is accounted for.
        $erroredTenants = $this->reportTenantCoverage($verdicts);
        $skippedTenants = $this->skippedTenantIds();
        $unaccounted = array_merge($erroredTenants, $skippedTenants);

        $this->newLine();

        if ($terminalsVerified === 0 && $unaccounted === [] && $exit === self::SUCCESS) {
            $this->info('No active terminals found.');

            if ($terminalFilter !== null || $companyFilter !== null) {
                // Fail closed: the operator named a chain and nothing was
                // verified. Exiting 0 here reported "verified" for a chain the
                // command never read — the exact class of false pass this
                // wave exists to remove.
                $this->error(
                    'The --terminal / --company filter matched no terminal in any reachable tenant. '.
                    'Nothing was verified.',
                );

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($hasFailure) {
            $this->error('Chain verification FAILED - one or more chains are broken.');

            return self::FAILURE;
        }

        if ($unaccounted !== []) {
            // B2/C2 (2026-08-05 fiscal review). `$hasFailure` only ever captured
            // verdicts the closure COMPUTED. A tenant whose closure threw, or
            // whose database could not be opened, left it false while
            // `$terminalsVerified` had already been incremented by the tenants
            // that did run — so control reached the success branch and printed
            // the exact string `docs/qa/2026-05-12-first-tenant-smoke.md:118`
            // ticks as PASS, then returned a non-zero exit nobody reads.
            $this->error(sprintf(
                'Chain verification INCOMPLETE - %d tenant(s) produced no verdict: %s. '.
                'Nothing was verified for them; this run is NOT evidence that their chains are intact.',
                count($unaccounted),
                implode(', ', $unaccounted),
            ));

            return self::FAILURE;
        }

        if ($exit !== self::SUCCESS) {
            // The base reported a non-zero aggregate for a reason the counters
            // above cannot see. Never print the PASS banner over it.
            $this->error('Chain verification did not complete cleanly - see the errors above.');

            return self::FAILURE;
        }

        $this->info('All chains verified successfully.');

        return self::SUCCESS;
    }

    /**
     * Resolve the terminals to verify inside the currently bound tenant.
     *
     * The explicit `tenant_id` predicate is redundant under
     * database-per-tenant and load-bearing in single-schema compatibility
     * mode, where one shared database holds every tenant's terminals.
     *
     * @return Collection<int, Terminal>
     */
    private function resolveTerminals(Tenant $tenant, ?string $companyFilter, ?string $terminalFilter): Collection
    {
        $query = Terminal::with('company')->where('tenant_id', $tenant->id);

        if ($terminalFilter !== null) {
            // A terminal id is explicit enough to bypass the `active()` scope,
            // exactly as it did before the conversion.
            return $query->where('id', $terminalFilter)->get();
        }

        if ($companyFilter !== null) {
            $query->where('company_id', $companyFilter);
        }

        return $query->active()->orderBy('code')->get();
    }

    /**
     * Verify receipt chain for a terminal.
     *
     * @return array{is_valid: bool, count: int, break_point: string}
     */
    private function verifyReceiptChain(Terminal $terminal): array
    {
        // Phase 1 fiscal-event projection carve-out (Task 21 F1 round-2) —
        // projection rows (fiscal_event_id IS NOT NULL) have their
        // authoritative integrity verified by `fiscal:verify-event-chain`
        // (Task 31). They are excluded from the legacy verifier because
        // their `fiscal_hash` is the canonical-bytes SHA-256 from the
        // fiscal event, not the legacy pipe-string SHA-256 this command
        // recomputes. See `ReceiptHashService::verifyTerminalChain()`
        // docblock for the full rationale.
        $count = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->count();

        if ($count === 0) {
            return [
                'is_valid' => true,
                'count' => 0,
                'break_point' => '-',
            ];
        }

        $isValid = $this->receiptHashService->verifyTerminalChain($terminal);

        $breakPoint = '-';
        if (! $isValid) {
            $breakPoint = $this->findReceiptChainBreak($terminal);
        }

        return [
            'is_valid' => $isValid,
            'count' => $count,
            'break_point' => $breakPoint,
        ];
    }

    /**
     * Find the receipt chain break point by iterating through receipts.
     */
    private function findReceiptChainBreak(Terminal $terminal): string
    {
        // Phase 1 fiscal-event projection carve-out (Task 21 F1 round-2).
        // See `verifyReceiptChain()` and `ReceiptHashService::verifyTerminalChain()`
        // docblocks for the full rationale.
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->whereNull('fiscal_event_id')
            ->where('fiscal_status', FiscalStatus::Fiscalized->value)
            ->where('is_voided', false)
            ->where('is_training', false)
            ->orderBy('chain_sequence')
            ->get();

        $previousHash = null;

        foreach ($receipts as $receipt) {
            if ($receipt->previous_hash !== $previousHash) {
                return sprintf('Sequence #%d (link)', $receipt->chain_sequence);
            }

            $expectedHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
            if ($expectedHash !== $receipt->fiscal_hash) {
                return sprintf('Sequence #%d (hash)', $receipt->chain_sequence);
            }

            $previousHash = $receipt->fiscal_hash;
        }

        // Chain data matches but terminal last_hash may be stale
        return 'Terminal last_hash mismatch';
    }

    /**
     * Verify Z-report chain for a terminal.
     *
     * @return array{is_valid: bool, count: int, break_point: string}
     */
    private function verifyZReportChain(Terminal $terminal): array
    {
        $count = ZReport::where('terminal_id', $terminal->id)->count();

        if ($count === 0) {
            return [
                'is_valid' => true,
                'count' => 0,
                'break_point' => '-',
            ];
        }

        $isValid = $this->zReportHashService->verifyZReportChain($terminal);

        $breakPoint = '-';
        if (! $isValid) {
            $brokenReport = $this->zReportHashService->findChainBreak($terminal);
            if ($brokenReport !== null) {
                $breakPoint = sprintf('Z%04d', $brokenReport->z_number);
            }
        }

        return [
            'is_valid' => $isValid,
            'count' => $count,
            'break_point' => $breakPoint,
        ];
    }
}
