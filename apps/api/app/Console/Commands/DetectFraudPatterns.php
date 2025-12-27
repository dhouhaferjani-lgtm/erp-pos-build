<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\AnomalyDetectionService;
use Illuminate\Console\Command;

/**
 * Scheduled command to detect fraud patterns across all companies.
 *
 * Runs daily to:
 * - Detect high draft abandonment rates
 * - Identify suspicious product patterns
 * - Create fraud alerts
 * - Trigger automated actions (counting, notifications)
 */
class DetectFraudPatterns extends Command
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
    protected $description = 'Detect fraud patterns and suspicious behavior across all companies';

    public function __construct(
        private readonly AnomalyDetectionService $anomalyDetection
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Starting fraud pattern detection...');

        $companies = $this->option('company')
            ? Company::where('id', $this->option('company'))->get()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->error('No companies found.');

            return self::FAILURE;
        }

        $this->info("Analyzing {$companies->count()} companies...");

        $totalAlertsCreated = 0;
        $totalFlaggedUsers = 0;

        foreach ($companies as $company) {
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
                $this->error("  ✗ Error processing company: {$e->getMessage()}");
                \Log::error('Fraud detection failed for company', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine();
        $this->info("✅ Detection complete!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Companies analyzed', $companies->count()],
                ['Flagged users', $totalFlaggedUsers],
                ['Alerts created', $this->option('dry-run') ? '0 (dry-run)' : $totalAlertsCreated],
            ]
        );

        return self::SUCCESS;
    }
}
