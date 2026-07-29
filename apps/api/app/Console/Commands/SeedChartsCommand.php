<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
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
 * seeder for every company of the CURRENT tenant by delegating to
 * {@see ChartOfAccountsService::seedForCompany()} — the single source of truth for
 * chart provisioning. The seeders are additive/idempotent: they add the
 * portfolio/fee accounts (cheques-to-collect, effects, discounted effects, bank
 * fees, recoverable VAT, doubtful receivables) without replacing existing accounts
 * and without assigning any repository balance, movement, or journal entry.
 *
 * Invoke fleet-wide the same way as the sibling backfill:
 *   php artisan tenants:run accounting:seed-charts [--dry-run]
 *
 * @cross-tenant-by-design Backfill run per tenant via `tenants:run`; iterates every company of the bound tenant to re-run chart provisioning.
 */
final class SeedChartsCommand extends Command
{
    protected $signature = 'accounting:seed-charts
                            {--dry-run : Report the accounts that would be added without writing them}';

    protected $description = 'Re-run idempotent chart-of-accounts provisioning for every company in the current tenant.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ChartOfAccountsService $charts,
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
        $created = 0;

        $companies = Company::query()->orderBy('id')->get();

        foreach ($companies as $company) {
            $before = $this->accountCount((string) $company->id);

            if ($dryRun) {
                $this->database->beginTransaction();
            }

            try {
                $this->charts->seedForCompany($company);
                $after = $this->accountCount((string) $company->id);
            } catch (Throwable $exception) {
                if ($dryRun) {
                    $this->database->rollBack();
                }

                Log::error('accounting:seed-charts failed for a company; aborting.', [
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'country_code' => strtoupper($company->country_code),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
                $this->error(sprintf(
                    'Company %s: chart provisioning failed (%s). No further companies were processed.',
                    $company->id,
                    $exception->getMessage(),
                ));

                return self::FAILURE;
            }

            if ($dryRun) {
                $this->database->rollBack();
            }

            $delta = $after - $before;
            $created += $delta;

            $this->line(sprintf(
                '%sCompany %s (%s): %d account(s) %s.',
                $dryRun ? '[DRY-RUN] ' : '',
                $company->id,
                strtoupper($company->country_code),
                $delta,
                $dryRun ? 'would be created' : 'created',
            ));
        }

        $this->info(sprintf(
            '%sChart provisioning: %d account(s) %s across %d company/companies.',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $dryRun ? 'would be created' : 'created',
            $companies->count(),
        ));

        return self::SUCCESS;
    }

    private function accountCount(string $companyId): int
    {
        return Account::query()->where('company_id', $companyId)->count();
    }
}
