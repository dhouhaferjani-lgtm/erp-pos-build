<?php

declare(strict_types=1);

namespace App\Modules\POS\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
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
 * Receipt verification is reported as three independently attributable arms:
 * authoritative fiscal events, projected receipt/event hash mirrors, and
 * legacy receipts. Z-report verification is unchanged.
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
                        foreach ($this->verifyReceiptChains($terminal) as $result) {
                            $rows[] = [
                                'terminal_code' => $terminal->code,
                                'company' => $companyName,
                                'chain_type' => $result['chain_type'],
                                'status' => $result['is_valid'] ? "\u{2713}" : "\u{2717}",
                                'count' => $result['count'],
                                'break_point' => $result['break_point'],
                            ];

                            if (! $result['is_valid']) {
                                $tenantFailed = true;
                            }
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
     * Verify and report each receipt-chain arm for a terminal.
     *
     * @return list<array{chain_type: string, is_valid: bool, count: int, break_point: string}>
     */
    private function verifyReceiptChains(Terminal $terminal): array
    {
        $arms = $this->receiptHashService->verifyTerminalChainArms($terminal);
        $labels = [
            'fiscal_events' => 'Receipts: Fiscal Events',
            'projected_mirror' => 'Receipts: Projected Mirror',
            'legacy' => 'Receipts: Legacy',
        ];
        $results = [];

        foreach ($labels as $key => $label) {
            $arm = $arms[$key];
            $results[] = [
                'chain_type' => $label,
                'is_valid' => $arm->isValid,
                'count' => $arm->count,
                'break_point' => $arm->breakPoint ?? '-',
            ];
        }

        return $results;
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
