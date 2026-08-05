<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * `fiscal:preflight-gate` — the operator gate that must clear before any
 * schema-destructive fiscal rebuild task runs (plan §1 / §161 sign-off).
 *
 * Tenant-isolation: cat-(a-per-tenant-iter), converted 2026-08-05.
 *
 * **The defect this conversion closes (cat-(b) re-sweep, highest severity).**
 * The gate probed four TENANT tables — `pos_receipts`, `pos_z_reports`,
 * `pos_receipt_prints`, `pos_terminals` — through a `tableCount()` helper that
 * returned `0` when `Schema::hasTable()` was false. After the 2026-05-28
 * database-per-tenant flip the console runs on the CENTRAL connection, where
 * none of those four tables exist. Every probe therefore answered "0 rows", the
 * gate printed **"SERVER SURFACE: clear"** and exited 0 — a green light in
 * front of a task that destroys fiscal schema, having inspected nothing.
 *
 * **Fail-closed contract.** "clear" is now emitted only for a tenant whose four
 * tables were actually read. Every other outcome is "unable to verify" plus a
 * non-zero exit:
 *   - a probed table is missing in a visited tenant (a mis-migrated tenant, not
 *     a clean surface);
 *   - a tenant's database could not be opened, so `forEachTenant()` skipped it
 *     (a WARNING-level skip is not a verdict — the gate never inherits it as a
 *     pass);
 *   - zero tenants were visited at all, which is exactly what a bare central
 *     run looks like.
 *
 * **Per-tenant evidence.** The gate feeds the launch program's E-7 evidence
 * pack, so every verdict line names the tenant it belongs to
 * (`TENANT <id> (<slug>) SERVER SURFACE: …`). The aggregate line keeps the
 * historical `SERVER SURFACE: clear` / `SERVER SURFACE: NON-EMPTY - see report`
 * wording so existing sign-off transcripts stay greppable.
 *
 * The DEVICE and WEB-POS surface lines are unchanged and still printed once:
 * the device inventory is manual, and the web-POS probe inspects the route
 * table, not tenant data.
 */
final class PreflightFiscalGateCommand extends TenantScopedCommand
{
    protected $signature = 'fiscal:preflight-gate
        {--tenant= : Verify only this tenant UUID; default is every tenant in the directory}';

    protected $description = 'Verify fiscal surfaces before POS fiscal event rebuild work begins, per tenant.';

    /**
     * The four server-side fiscal surfaces the gate inspects. A missing table
     * here is a verification failure, never a zero count.
     *
     * @var list<string>
     */
    private const array PROBED_TABLES = [
        'pos_receipts',
        'pos_z_reports',
        'pos_receipt_prints',
        'pos_terminals',
    ];

    public function __construct(CompanyContext $companyContext)
    {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $gatedTenants = 0;
        $unverifiableTenants = 0;
        $nonEmptyTenants = 0;

        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use (&$gatedTenants, &$unverifiableTenants, &$nonEmptyTenants): int {
                $gatedTenants++;

                try {
                    $findings = $this->serverSurfaceFindings($tenant);
                } catch (Throwable $exception) {
                    $unverifiableTenants++;
                    $this->line(sprintf(
                        'TENANT %s (%s) SERVER SURFACE: unable to verify - %s',
                        $tenant->id,
                        $tenant->slug,
                        $exception->getMessage(),
                    ));

                    return self::FAILURE;
                }

                if (array_sum($findings) === 0) {
                    $this->line(sprintf(
                        'TENANT %s (%s) SERVER SURFACE: clear',
                        $tenant->id,
                        $tenant->slug,
                    ));

                    return self::SUCCESS;
                }

                $nonEmptyTenants++;
                $this->line(sprintf(
                    'TENANT %s (%s) SERVER SURFACE: NON-EMPTY - see report',
                    $tenant->id,
                    $tenant->slug,
                ));
                $this->reportServerFindings($findings);

                return self::FAILURE;
            },
        );

        $skipped = count($this->skippedTenantIds());

        if ($gatedTenants === 0) {
            // The bare-central case (and the case where `--tenant` named a
            // tenant the directory does not hold). Emitting "clear" here is
            // precisely the false green light this conversion exists to remove.
            $this->line(
                'SERVER SURFACE: unable to verify - no tenant database was inspected, so no fiscal surface was verified',
            );
            $this->printNonServerSurfaces();
            $this->error(
                'Preflight gate FAILED CLOSED: zero tenants verified. Do not proceed with a schema-destructive '.
                'fiscal rebuild on the strength of this run.',
            );

            return $exit === self::SUCCESS ? self::FAILURE : $exit;
        }

        if ($unverifiableTenants > 0 || $skipped > 0) {
            $this->line(sprintf(
                'SERVER SURFACE: unable to verify - %d tenant(s) could not be inspected (%d probe failure(s), %d unreachable database(s))',
                $unverifiableTenants + $skipped,
                $unverifiableTenants,
                $skipped,
            ));
            $exit = $exit === self::SUCCESS ? self::FAILURE : $exit;
        } elseif ($nonEmptyTenants > 0) {
            $this->line('SERVER SURFACE: NON-EMPTY - see report');
        } else {
            $this->line('SERVER SURFACE: clear');
        }

        $this->printNonServerSurfaces();

        return $exit;
    }

    /**
     * The two surfaces that are not tenant-scoped: the device inventory is a
     * manual per-terminal exercise, and the web-POS probe reads the route
     * table. Printed once per run, after the per-tenant server verdicts.
     */
    private function printNonServerSurfaces(): void
    {
        $this->line('DEVICE SURFACE: requires manual inventory - record per-terminal SQLite findings in the sign-off');
        $this->line($this->webPosPathLive()
            ? 'WEB-POS SURFACE: live receipt-creation path detected - disposition per section 14.2'
            : 'WEB-POS SURFACE: no live receipt-creation path');
    }

    /**
     * @return array<string, int>
     *
     * @throws RuntimeException when a probed table does not exist on the bound
     *                          connection — the caller turns that into an
     *                          "unable to verify" verdict, never a zero count.
     */
    private function serverSurfaceFindings(Tenant $tenant): array
    {
        foreach (self::PROBED_TABLES as $table) {
            $this->assertTableExists($table);
        }

        $tenantId = (string) $tenant->id;

        return [
            'pos_receipts' => DB::table('pos_receipts')->where('tenant_id', $tenantId)->count(),
            'pos_z_reports' => DB::table('pos_z_reports')
                ->whereIn('terminal_id', $this->terminalIdsQuery($tenantId))
                ->count(),
            'pos_receipt_prints' => DB::table('pos_receipt_prints')
                ->whereIn('terminal_id', $this->terminalIdsQuery($tenantId))
                ->count(),
            'pos_terminals_with_chain_state' => DB::table('pos_terminals')
                ->where('tenant_id', $tenantId)
                // Grouped deliberately: a bare `->whereNotNull(...)->orWhere(...)`
                // alongside the tenant predicate binds as
                // `(tenant AND last_hash IS NOT NULL) OR sequence > 0`, which
                // hands every tenant every other tenant's chain state in the
                // single-schema compatibility mode.
                ->where(static fn ($query) => $query
                    ->whereNotNull('last_hash')
                    ->orWhere('current_sequence', '>', 0))
                ->count(),
        ];
    }

    /**
     * `pos_z_reports` and `pos_receipt_prints` carry no `tenant_id` of their
     * own — they anchor on `terminal_id`. Redundant under database-per-tenant,
     * load-bearing in single-schema compatibility mode where one shared
     * database holds every tenant's rows.
     *
     * @return Builder
     */
    private function terminalIdsQuery(string $tenantId)
    {
        return DB::table('pos_terminals')->select('id')->where('tenant_id', $tenantId);
    }

    /**
     * @param  array<string, int>  $serverFindings
     */
    private function reportServerFindings(array $serverFindings): void
    {
        foreach ($serverFindings as $source => $count) {
            if ($count === 0) {
                continue;
            }

            $this->line(sprintf('  - %s: %d', $source, $count));
        }
    }

    /**
     * Fail-closed replacement for the old `tableCount()` short-circuit.
     *
     * "The table is not here" and "the table is here and empty" are different
     * facts and only one of them is a clean fiscal surface. Inside
     * `forEachTenant()` the first means a mis-migrated tenant.
     */
    private function assertTableExists(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException(sprintf(
                'MISSING TABLE %s on the bound connection; the fiscal surface cannot be verified',
                $table,
            ));
        }
    }

    private function webPosPathLive(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (in_array($route->uri(), ['pos/receipts', 'api/v1/pos/receipts'], true)) {
                return true;
            }
        }

        return false;
    }
}
