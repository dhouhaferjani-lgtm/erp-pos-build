<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled command to detect fraud patterns across the fleet.
 *
 * Runs daily to:
 * - Detect high draft abandonment rates
 * - Identify suspicious product patterns
 * - Create fraud alerts
 * - Trigger automated actions (counting, notifications)
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). `companies` is a TENANT table, and
 * until 2026-08-05 `Company::all()` sat at the TOP of handle() — OUTSIDE the
 * per-company try/catch. Under database-per-tenant (flipped 2026-05-28) that
 * query raises 42P01 on the scheduler's CENTRAL connection and the exception
 * escapes the whole command, so nightly fraud detection never analysed a single
 * company. `runInBackground()` with no `->onFailure()` on the schedule entry
 * meant the non-zero exit was never observed either.
 *
 * The company enumeration now happens INSIDE `forEachTenant()`, so it reads the
 * tenant's own database. `--company` stays what it always was — an in-tenant
 * narrowing filter — but an operator who narrows to a company no reachable
 * tenant owns now gets a non-zero exit instead of a clean "0 analysed" run,
 * mirroring {@see TenantScopedCommand::failIfTenantFilterUnvisited()}.
 *
 * **Legacy row-level mode** (`tenancy_resolver.db_per_tenant=false`): the
 * closure runs once per tenant against ONE shared database, so the company
 * query carries an explicit `tenant_id` predicate — the same guard `d6ed4e481`
 * added to the batch queries. Without it every tenant's pass would re-analyse
 * (and re-alert on) every other tenant's companies.
 */
final class DetectFraudPatterns extends TenantScopedCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fraud:detect
                          {--company= : Run detection for specific company only}
                          {--dry-run : Preview results without creating alerts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect fraud patterns and suspicious behavior across all companies, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly AnomalyDetectionService $anomalyDetection,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $this->info('🔍 Starting fraud pattern detection...');

        $companyFilter = $this->stringOption('company');

        $analysedCompanies = 0;
        $totalAlertsCreated = 0;
        $totalFlaggedUsers = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $companyFilter,
            &$analysedCompanies,
            &$totalAlertsCreated,
            &$totalFlaggedUsers,
        ): int {
            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                ->get();

            if ($companies->isEmpty()) {
                // A tenant that owns no company (or none matching --company) is
                // not a failure — the aggregate must stay clean so the
                // scheduler's onFailure() hook keeps meaning "something broke".
                return self::SUCCESS;
            }

            $tenantExit = self::SUCCESS;

            foreach ($companies as $company) {
                $analysedCompanies++;

                $this->newLine();
                $this->line("📊 Company: {$company->name} ({$company->id})");

                try {
                    // Detect high abandonment rates
                    $flaggedUsers = $this->anomalyDetection->detectHighAbandonmentRate($company->id);

                    if (empty($flaggedUsers)) {
                        $this->info('  ✓ No suspicious patterns detected');

                        continue;
                    }

                    $totalFlaggedUsers += count($flaggedUsers);

                    foreach ($flaggedUsers as $userData) {
                        $this->warn("  ⚠️  User: {$userData['user_name']} ({$userData['user_id']})");
                        $this->line("      Abandoned drafts: {$userData['abandoned_count']}");

                        if (! empty($userData['flagged_products'])) {
                            $this->line('      Flagged products:');
                            foreach (array_slice($userData['flagged_products'], 0, 5) as $product) {
                                $this->line("        - {$product['product_name']} ({$product['count']} times)");
                            }
                        }

                        $totalAlertsCreated++;
                    }

                    // Create alerts unless dry-run
                    if (! $this->option('dry-run')) {
                        $this->anomalyDetection->detectAndAct($company->id);
                        $this->info('  ✓ Fraud alerts created');
                    }
                } catch (\Throwable $e) {
                    // Per-COMPANY isolation (pre-existing behaviour, kept): one
                    // company's detection failure must not cost the rest of the
                    // tenant its nightly sweep. Unlike before, it now also
                    // degrades the exit code so the scheduler alert fires.
                    $this->error("  ✗ Error processing company: {$e->getMessage()}");
                    Log::error('Fraud detection failed for company', [
                        'tenant_id' => $tenant->id,
                        'company_id' => $company->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $tenantExit = self::FAILURE;
                }
            }

            return $tenantExit;
        });

        $this->newLine();
        $this->info('✅ Detection complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Companies analyzed', $analysedCompanies],
                ['Flagged users', $totalFlaggedUsers],
                ['Alerts created', $this->option('dry-run') ? '0 (dry-run)' : $totalAlertsCreated],
            ]
        );

        if ($companyFilter !== null && $analysedCompanies === 0) {
            $this->error(sprintf(
                'Company %s was not found in any reachable tenant. Nothing was analysed — verify the id, '.
                'and check the application log for tenants the database probe skipped.',
                $companyFilter,
            ));

            return self::INVALID;
        }

        return $exit;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
