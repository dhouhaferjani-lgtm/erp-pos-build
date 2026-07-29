<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\Bank;
use Database\Seeders\BanksSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Productized replacement for the Phase-2 / bank-directory ad-hoc tinker backfill
 * (docs/handoff/bank-directory-deploy-checklist.md §2). Seeds the canonical bank
 * directory onto every company of the CURRENT tenant by delegating to
 * {@see BanksSeeder} — the single source of truth for the directory rows and their
 * idempotency contract (row existence matched on tenant/country/rib_bank_code, name
 * for null-code banks; canonical rows refresh only bic/position/city; custom banks
 * are never touched).
 *
 * Invoke fleet-wide the same way as the sibling backfill:
 *   php artisan tenants:run treasury:backfill-banks [--dry-run]
 *
 * This deliberately iterates explicit {@see Company} Eloquent instances rather than
 * `db:seed --class=BanksSeeder`, which silently no-ops because the container resolves
 * the seeder's `?Company` parameter to an empty Company (checklist §2 trap).
 *
 * @cross-tenant-by-design Backfill run per tenant via `tenants:run`; iterates every company of the bound tenant to seed the shared bank directory.
 */
final class BackfillBanksCommand extends Command
{
    protected $signature = 'treasury:backfill-banks
                            {--dry-run : Report the directory rows that would be created without writing them}';

    protected $description = 'Backfill the canonical bank directory for every company in the current tenant.';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly BanksSeeder $seeder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('banks')) {
            $this->error(
                'Tenant tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        $companies = Company::query()->orderBy('id')->get();

        foreach ($companies as $company) {
            $countryCode = strtoupper($company->country_code);
            $directory = database_path("data/banks/{$countryCode}.json");

            if (! is_file($directory)) {
                $this->warn(sprintf(
                    'Company %s (%s): no bank directory ships for country %s; skipped.',
                    $company->id,
                    $company->name,
                    $countryCode,
                ));
                $skipped++;

                continue;
            }

            $before = $this->bankCount((string) $company->tenant_id);

            if ($dryRun) {
                $this->database->beginTransaction();
            }

            try {
                $this->seeder->run($company);
                $after = $this->bankCount((string) $company->tenant_id);
            } catch (Throwable $exception) {
                if ($dryRun) {
                    $this->database->rollBack();
                }

                Log::error('treasury:backfill-banks failed for a company; aborting.', [
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'country_code' => $countryCode,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
                $this->error(sprintf(
                    'Company %s: bank backfill failed (%s). No further companies were processed.',
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
                '%sCompany %s (%s): %d bank(s) %s.',
                $dryRun ? '[DRY-RUN] ' : '',
                $company->id,
                $countryCode,
                $delta,
                $dryRun ? 'would be created' : 'created',
            ));
        }

        $this->info(sprintf(
            '%sBank directory backfill: %d row(s) %s across %d company/companies; %d skipped (no directory).',
            $dryRun ? '[DRY-RUN] ' : '',
            $created,
            $dryRun ? 'would be created' : 'created',
            $companies->count() - $skipped,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function bankCount(string $tenantId): int
    {
        return Bank::query()->where('tenant_id', $tenantId)->count();
    }
}
