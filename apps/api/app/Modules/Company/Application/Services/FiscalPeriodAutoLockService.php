<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service for automatically locking fiscal periods and fiscal years.
 *
 * THREE-STEP AUTO-LOCK PROCESS:
 * - STEP 1: Lock periods ended > threshold (Open → Closed)
 * - STEP 2: Mark fiscal years as closed when ended (is_closed = true)
 * - STEP 3: Lock all periods in closed fiscal years (Open/Closed → Locked)
 *
 * Business Rules:
 * - Threshold is country-specific (e.g., 1 month for TN/FR)
 * - Already locked periods are not modified (idempotent)
 * - Operates across all companies and tenants
 * - Runs daily at 1:00 AM via Laravel scheduler
 *
 * Performance:
 * - Uses bulk updates for efficiency
 * - Single database transaction ensures atomicity
 * - Handles thousands of periods per execution
 *
 * @see CountryFiscalRulesProvider For country-specific thresholds
 * @see PeriodStatus For status enum values
 */
final class FiscalPeriodAutoLockService
{
    public function __construct(
        private readonly CountryFiscalRulesProvider $rulesProvider
    ) {}

    /**
     * Lock expired periods and close ended fiscal years.
     */
    public function lockExpiredPeriods(): void
    {
        $startTime = microtime(true);

        try {
            DB::transaction(function () use (&$periodsClosed, &$yearsClosedCount, &$periodsLocked): void {
                // STEP 1: Lock periods ended more than threshold ago (Open → Closed)
                $periodsClosed = $this->lockOldPeriods();

                // STEP 2: Mark fiscal years as closed when they have ended
                $yearsClosedCount = $this->closeFiscalYears();

                // STEP 3: Lock all periods in closed fiscal years (Open/Closed → Locked)
                $periodsLocked = $this->lockPeriodsInClosedFiscalYears();
            });

            \Log::info('Fiscal period auto-lock completed', [
                'periods_closed' => $periodsClosed ?? 0,
                'fiscal_years_closed' => $yearsClosedCount ?? 0,
                'periods_locked' => $periodsLocked ?? 0,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        } catch (\Exception $e) {
            \Log::error('Fiscal period auto-lock failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            throw $e;
        }
    }

    /**
     * STEP 1: Lock periods that ended more than threshold ago.
     *
     * Changes status from Open to Closed. This is the first step of the auto-lock
     * process. Does not affect periods already Closed or Locked.
     *
     * Threshold is country-specific and defined in CountryFiscalRulesProvider.
     * Currently: 1 month for Tunisia and France.
     *
     * @return int Number of periods locked
     */
    private function lockOldPeriods(): int
    {
        // For now, all countries use 1 month, but this is now configurable
        // Get default country (most common) threshold
        $rules = $this->rulesProvider->getRulesForCountry('TN');
        $threshold = $rules->periodAutoLockMonths;
        $cutoffDate = Carbon::now()->subMonths($threshold);

        return FiscalPeriod::where('status', PeriodStatus::Open)
            ->where('end_date', '<', $cutoffDate)
            ->update(['status' => PeriodStatus::Closed]);
    }

    /**
     * STEP 2: Mark fiscal years as closed when their end_date has passed.
     *
     * Changes is_closed from false to true. This triggers STEP 3 to lock all
     * periods within these years. Fiscal years are never reopened once closed.
     *
     * @return int Number of fiscal years closed
     */
    private function closeFiscalYears(): int
    {
        $today = Carbon::now();

        return FiscalYear::where('is_closed', false)
            ->where('end_date', '<', $today)
            ->update(['is_closed' => true]);
    }

    /**
     * STEP 3: Lock all periods in closed fiscal years.
     *
     * Changes status from Open/Closed to Locked. This is the final lock status
     * that prevents any modifications. Once Locked, periods remain so permanently.
     *
     * @return int Number of periods locked
     */
    private function lockPeriodsInClosedFiscalYears(): int
    {
        // Get all closed fiscal year IDs
        $closedFiscalYearIds = FiscalYear::where('is_closed', true)
            ->pluck('id');

        // Lock all periods in those fiscal years (except already locked)
        return FiscalPeriod::whereIn('fiscal_year_id', $closedFiscalYearIds)
            ->whereIn('status', [PeriodStatus::Open, PeriodStatus::Closed])
            ->update(['status' => PeriodStatus::Locked]);
    }
}
