<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\LegacyExistingChartRepairPreviewer;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Productized replacement for the Phase-2 ad-hoc chart-provisioning tinker step
 * (docs/handoff/treasury-phase2-deploy-checklist.md §2). Re-runs the locale chart
 * legacy seeder plus approved variance installer for every company of the CURRENT tenant. With legacy provisioning
 * active it delegates writes to {@see ChartOfAccountsService::seedForCompany()}.
 * With template provisioning active, writes fail closed and the rollback-owning
 * {@see LegacyExistingChartRepairPreviewer} remains available for diagnostics only.
 * The frozen seeders are additive/idempotent: they add the
 * portfolio/fee accounts (cheques-to-collect, effects, discounted effects, bank
 * fees, recoverable VAT, doubtful receivables) without replacing existing accounts
 * and without assigning any repository balance, movement, or journal entry.
 *
 * ## Fleet invocation
 *
 * Runs per tenant via stancl `tenants:run`. The bare `--dry-run` flag is NOT valid
 * under `tenants:run` (it errors); pass options with the `--option=` form:
 *
 *   php artisan tenants:run accounting:seed-charts                     # apply, all tenants
 *   php artisan tenants:run accounting:seed-charts --option=dry-run=1  # preview, all tenants
 *   php artisan tenants:run accounting:seed-charts --tenants=<uuid>    # scope to one tenant
 *
 * Direct (already inside a bound tenant context): `php artisan accounting:seed-charts [--dry-run]`.
 * This existing-company repair command fails closed when country-defaults template provisioning
 * is enabled because assigned templates are creation-only and must never mutate a live chart.
 * Under that flag, `--dry-run` is a legacy-repair diagnostic only: it is not assigned-template
 * parity and cannot be applied by this command. Run `country-defaults:verify` for assignment and
 * certification health; use a reviewed one-off migration for any required live-chart repair.
 *
 * ## Operational contract
 *
 * - **Exit codes:** exit 1 on unavailable tenant tables, a flag-true write attempt,
 *   or a delegate throw; "no companies" is exit 0 by design. `tenants:run` discards child exit codes
 *   (vendor behavior), so deploy verification greps stdout for the stable markers below.
 * - **Grep-gate markers (stdout):**
 *   - success/summary: `Chart provisioning:`
 *   - dry-run: `[DRY-RUN]`
 *   - flag-true diagnostic: `[LEGACY-ONLY PREVIEW]` + `[NOT ASSIGNED-TEMPLATE PARITY]`
 *   - abort/failure: `No further companies were processed.` (paired log literal
 *     `accounting:seed-charts failed for a company; aborting.`)
 * - **Honest reporting:** the chart seeders may promote existing accounts to
 *   system-managed and re-issue parent links, so the summary reports promotions and
 *   parent rewrites alongside creations (before/after snapshot within the preview
 *   transaction; cheap column compare). A second apply is command-level idempotent:
 *   zero creations. Under the template flag those counts describe only the retired
 *   legacy repair baseline and are never represented as template drift or an apply plan.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `companies` and the chart tables are TENANT tables, so
 *   post-2026-05-28 (database-per-tenant) this command reads ONLY the tenant database `tenants:run` binds around it.
 *   `php artisan accounting:seed-charts` on its own runs on the CENTRAL connection where neither table exists — and is
 *   stopped BEFORE any query by the fail-closed `Schema::hasTable('companies') || Schema::hasTable('accounts')` guard
 *   that opens `handle()` (:74-80), which returns FAILURE with an operator message. (Corrected 2026-08-05, wave-2
 *   tenancy review R4: the annotation previously claimed a bare run "raises 42P01", which the guard makes impossible.)
 *   Invoke exclusively as `php artisan tenants:run accounting:seed-charts`.
 */
final class SeedChartsCommand extends Command
{
    protected $signature = 'accounting:seed-charts
                            {--dry-run : Report the accounts that would be created/promoted/reparented without writing them}';

    protected $description = 'Re-run idempotent chart-of-accounts provisioning for every company in the current tenant.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ChartOfAccountsService $charts,
        private readonly LegacyExistingChartRepairPreviewer $legacyPreviewer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error(
                'Tenant tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $templateProvisioningEnabled = (bool) config('country_defaults.provisioning_enabled', false);

        if (! $dryRun && $templateProvisioningEnabled) {
            $this->error(
                'accounting:seed-charts is disabled while country-defaults template provisioning is enabled; templates apply only during new-company creation.',
            );

            return self::FAILURE;
        }

        $legacyOnlyPreview = $dryRun && $templateProvisioningEnabled;
        if ($legacyOnlyPreview) {
            $this->warn('[LEGACY-ONLY PREVIEW]');
            $this->warn('[NOT ASSIGNED-TEMPLATE PARITY]');
            $this->warn('Results use the retired legacy repair baseline and cannot be applied by this command.');
            $this->warn('Run country-defaults:verify for assignment health; use a reviewed migration for live-chart repair.');
        }

        $companies = Company::query()->orderBy('id')->get();

        $created = 0;
        $promoted = 0;
        $reparented = 0;
        /** @var array{company: Company, exception: Throwable}|null $failure */
        $failure = null;

        if ($dryRun && ! $legacyOnlyPreview) {
            $this->database->beginTransaction();
        }

        try {
            foreach ($companies as $company) {
                try {
                    if ($legacyOnlyPreview) {
                        [$companyCreated, $companyPromoted, $companyReparented] = $this->legacyPreviewer->preview($company);
                    } else {
                        $before = $this->snapshot((string) $company->id);
                        $this->charts->seedForCompany($company);
                        [$companyCreated, $companyPromoted, $companyReparented] = $this->diff($before, $this->snapshot((string) $company->id));
                    }
                } catch (Throwable $exception) {
                    $failure = ['company' => $company, 'exception' => $exception];

                    break;
                }

                $created += $companyCreated;
                $promoted += $companyPromoted;
                $reparented += $companyReparented;

                $this->line(sprintf(
                    '%sCompany %s (%s): %d created, %d promoted, %d reparented.',
                    $dryRun ? '[DRY-RUN] ' : '',
                    $company->id,
                    strtoupper($company->country_code),
                    $companyCreated,
                    $companyPromoted,
                    $companyReparented,
                ));
            }
        } finally {
            // The snapshot reads and the per-company reporting sit OUTSIDE the delegate
            // try/catch, so a query failure there would otherwise bypass the rollback and
            // hand `tenants:run` back a connection with an open (on PostgreSQL, aborted)
            // transaction that poisons every later tenant in the fleet loop.
            if ($dryRun && ! $legacyOnlyPreview) {
                $this->database->rollBack();
            }
        }

        if ($failure !== null) {
            Log::error('accounting:seed-charts failed for a company; aborting.', [
                'tenant_id' => $failure['company']->tenant_id,
                'company_id' => $failure['company']->id,
                'country_code' => strtoupper($failure['company']->country_code),
                'exception_class' => $failure['exception']::class,
                'exception_message' => $failure['exception']->getMessage(),
            ]);
            $this->error(sprintf(
                'Company %s: chart provisioning failed (%s). No further companies were processed.',
                $failure['company']->id,
                $failure['exception']->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sChart provisioning: %d created, %d promoted, %d reparented across %d company/companies.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $promoted,
            $reparented,
            $companies->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Snapshot the fields chart provisioning may change (system flag + parent link),
     * keyed by account id, so creations, promotions, and reparents are counted honestly.
     *
     * @return array<string, array{is_system: bool, parent_id: string|null}>
     */
    private function snapshot(string $companyId): array
    {
        /** @var array<string, array{is_system: bool, parent_id: string|null}> $rows */
        $rows = [];
        foreach (Account::query()->where('company_id', $companyId)->get(['id', 'is_system', 'parent_id']) as $account) {
            $rows[(string) $account->id] = [
                'is_system' => (bool) $account->is_system,
                'parent_id' => $account->parent_id === null ? null : (string) $account->parent_id,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{is_system: bool, parent_id: string|null}>  $before
     * @param  array<string, array{is_system: bool, parent_id: string|null}>  $after
     * @return array{int, int, int} [created, promoted, reparented]
     */
    private function diff(array $before, array $after): array
    {
        $created = 0;
        $promoted = 0;
        $reparented = 0;

        foreach ($after as $id => $row) {
            if (! array_key_exists($id, $before)) {
                $created++;

                continue;
            }

            if (! $before[$id]['is_system'] && $row['is_system']) {
                $promoted++;
            }

            if ($before[$id]['parent_id'] !== $row['parent_id']) {
                $reparented++;
            }
        }

        return [$created, $promoted, $reparented];
    }
}
