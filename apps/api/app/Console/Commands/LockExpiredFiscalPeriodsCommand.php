<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use Illuminate\Console\Command;

/**
 * Command to automatically lock expired fiscal periods and close ended fiscal years.
 *
 * This command runs daily via Laravel scheduler at 1:00 AM to:
 * - Lock periods that ended more than 1 month ago
 * - Mark fiscal years as closed when they have ended
 * - Lock all periods in closed fiscal years
 *
 * SCHEDULING:
 * - Runs: Daily at 1:00 AM
 * - Overlap prevention: withoutOverlapping()
 * - Background execution: runInBackground()
 *
 * ERROR HANDLING:
 * - Database connection fails → Transaction rolls back, no data changed
 * - Concurrent execution → Laravel prevents overlap, only one runs
 * - Execution timeout → Runs in background, doesn't block other tasks
 * - Partial failure → Transaction ensures all-or-nothing update
 *
 * MONITORING:
 * - Success: Exit code 0
 * - Failure: Exit code 1 with error message logged
 * - Duration: Typically < 1 second for 1000s of periods
 * - Logs: Check storage/logs/laravel.log for metrics
 *
 * TROUBLESHOOTING:
 * - If periods not locking: Check if companies have correct country_code
 * - If command not running: Verify scheduler cron job is configured
 * - Manual execution: php artisan fiscal:lock-expired-periods
 * - Dry run mode: php artisan fiscal:lock-expired-periods --dry-run
 *
 * Business Rules:
 * - Periods ended >1 month ago: Open → Closed
 * - Fiscal years past end_date: is_closed = true
 * - All periods in closed fiscal years: Open/Closed → Locked
 *
 * @see FiscalPeriodAutoLockService For implementation details
 * @see CountryFiscalRulesProvider For country-specific thresholds
 */
final class LockExpiredFiscalPeriodsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fiscal:lock-expired-periods
                            {--dry-run : Display what would be locked without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lock expired fiscal periods and close ended fiscal years';

    /**
     * Execute the console command.
     */
    public function handle(FiscalPeriodAutoLockService $service): int
    {
        $this->info('Starting fiscal period auto-lock process...');

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN MODE: No changes will be made');

            return self::SUCCESS;
        }

        try {
            $service->lockExpiredPeriods();

            $this->info('✓ Successfully locked expired periods and closed ended fiscal years');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Failed to lock expired periods: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
