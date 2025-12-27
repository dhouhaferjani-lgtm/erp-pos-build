<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates fiscal years and periods for a company.
 *
 * Applies country-specific rules to create:
 * - 3 fiscal years (typically: 1 past, 1 current, 1 future)
 * - 12 monthly periods per year
 * - Proper status assignment (open/closed)
 */
final class FiscalYearCreationService
{
    public function __construct(
        private readonly CountryFiscalRulesProvider $rulesProvider
    ) {}

    public function createFiscalYearsForCompany(Company $company): void
    {
        $rules = $this->rulesProvider->getRulesForCountry($company->country_code);
        $effectiveStartMonth = $rules->getEffectiveStartMonth($company->fiscal_year_start_month);
        $referenceDate = new \DateTimeImmutable;

        $yearsToCreate = $rules->getYearsToCreate($effectiveStartMonth, $referenceDate);

        DB::transaction(function () use ($company, $rules, $effectiveStartMonth, $yearsToCreate, $referenceDate): void {
            // Create past year(s)
            for ($year = $yearsToCreate['past']; $year < $yearsToCreate['current']; $year++) {
                $this->createFiscalYear($company, $year, $effectiveStartMonth, $rules->autoClosePastYears, $referenceDate);
            }

            // Create current year
            $this->createFiscalYear($company, $yearsToCreate['current'], $effectiveStartMonth, false, $referenceDate);

            // Create future year(s)
            for ($year = $yearsToCreate['current'] + 1; $year <= $yearsToCreate['future']; $year++) {
                $this->createFiscalYear($company, $year, $effectiveStartMonth, false, $referenceDate);
            }
        });

        Log::info('Created fiscal years for company', [
            'company_id' => $company->id,
            'country' => $company->country_code,
            'years' => range($yearsToCreate['past'], $yearsToCreate['future']),
        ]);
    }

    private function createFiscalYear(
        Company $company,
        int $fiscalYear,
        int $startMonth,
        bool $isClosed,
        \DateTimeImmutable $referenceDate
    ): FiscalYear {
        // Calculate boundaries
        $startDate = Carbon::create($fiscalYear, $startMonth, 1);

        if ($startDate === null) {
            throw new \InvalidArgumentException("Invalid fiscal year date: {$fiscalYear}-{$startMonth}-01");
        }

        $startDate = $startDate->startOfDay();
        $endDate = $startDate->copy()->addMonths(12)->subDay()->endOfDay();

        $fiscalYearModel = FiscalYear::create([
            'company_id' => $company->id,
            'name' => (string) $fiscalYear,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_closed' => $isClosed,
            'closed_at' => $isClosed ? $endDate->copy()->addDay() : null,
            'closed_by' => null,
        ]);

        // Create 12 monthly periods
        $this->createMonthlyPeriods($fiscalYearModel, $startDate, $endDate, $isClosed, Carbon::instance($referenceDate));

        return $fiscalYearModel;
    }

    private function createMonthlyPeriods(
        FiscalYear $fiscalYear,
        Carbon $startDate,
        Carbon $endDate,
        bool $isClosed,
        Carbon $now
    ): void {
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];

        $currentDate = $startDate->copy();

        for ($periodNumber = 1; $periodNumber <= 12; $periodNumber++) {
            $periodStart = $currentDate->copy()->startOfMonth();
            $periodEnd = $currentDate->copy()->endOfMonth();

            if ($periodEnd->gt($endDate)) {
                $periodEnd = $endDate->copy();
            }

            $status = $this->determinePeriodStatus($periodEnd, $isClosed, $now);
            $monthIndex = (int) $periodStart->format('n') - 1;

            FiscalPeriod::create([
                'fiscal_year_id' => $fiscalYear->id,
                'company_id' => $fiscalYear->company_id,
                'period_number' => $periodNumber,
                'name' => "{$monthNames[$monthIndex]} {$periodStart->year}",
                'start_date' => $periodStart,
                'end_date' => $periodEnd,
                'status' => $status,
                'closed_at' => $status === PeriodStatus::Closed ? $periodEnd->copy()->addDay() : null,
                'closed_by' => null,
            ]);

            $currentDate->addMonth();
        }
    }

    private function determinePeriodStatus(Carbon $periodEnd, bool $fiscalYearClosed, Carbon $now): PeriodStatus
    {
        if ($fiscalYearClosed) {
            return PeriodStatus::Closed;
        }

        // Close periods >1 month old
        if ($periodEnd->lt($now->copy()->subMonth())) {
            return PeriodStatus::Closed;
        }

        return PeriodStatus::Open;
    }
}
