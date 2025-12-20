<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * FiscalYearSeeder
 *
 * Seeds fiscal year and period data for testing accounting reports.
 *
 * Creates:
 * - Fiscal Year 2024 (closed) with 12 monthly periods
 * - Fiscal Year 2025 (open) with 12 monthly periods
 * - Fiscal Year 2026 (future) with 12 monthly periods
 *
 * This allows testing of:
 * - Trial Balance reports across different periods
 * - General Ledger with historical data
 * - Profit & Loss for year-to-date and full-year comparisons
 * - Balance Sheet at various points in time
 * - Period closing workflows
 */
class FiscalYearSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all existing companies
        $companies = Company::all();

        if ($companies->isEmpty()) {
            $this->command->warn('No companies found. Run TenantSeeder first.');
            return;
        }

        foreach ($companies as $company) {
            $this->seedFiscalYearsForCompany($company);
        }
    }

    /**
     * Seed fiscal years and periods for a specific company.
     *
     * @param Company $company
     * @return void
     */
    private function seedFiscalYearsForCompany(Company $company): void
    {
        $this->command->info("Seeding fiscal years for company: {$company->name}");

        // Create 2024 fiscal year (closed)
        $year2024 = $this->createFiscalYear(
            company: $company,
            name: '2024',
            startDate: Carbon::parse('2024-01-01'),
            endDate: Carbon::parse('2024-12-31'),
            isClosed: true
        );

        // Create 2025 fiscal year (current/open)
        $year2025 = $this->createFiscalYear(
            company: $company,
            name: '2025',
            startDate: Carbon::parse('2025-01-01'),
            endDate: Carbon::parse('2025-12-31'),
            isClosed: false
        );

        // Create 2026 fiscal year (future)
        $year2026 = $this->createFiscalYear(
            company: $company,
            name: '2026',
            startDate: Carbon::parse('2026-01-01'),
            endDate: Carbon::parse('2026-12-31'),
            isClosed: false
        );

        $this->command->info("  ✓ Created 3 fiscal years (2024-2026)");
    }

    /**
     * Create a fiscal year with 12 monthly periods.
     *
     * @param Company $company
     * @param string $name
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param bool $isClosed
     * @return FiscalYear
     */
    private function createFiscalYear(
        Company $company,
        string $name,
        Carbon $startDate,
        Carbon $endDate,
        bool $isClosed
    ): FiscalYear {
        // Create or update fiscal year
        $fiscalYear = FiscalYear::updateOrCreate(
            [
                'company_id' => $company->id,
                'name' => $name,
            ],
            [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => $isClosed,
                'closed_at' => $isClosed ? $startDate->copy()->addYear()->startOfYear() : null,
                'closed_by' => null,
            ]
        );

        // Create 12 monthly periods
        $this->createMonthlyPeriods($fiscalYear, $startDate, $endDate, $isClosed);

        return $fiscalYear;
    }

    /**
     * Create 12 monthly periods for a fiscal year.
     *
     * @param FiscalYear $fiscalYear
     * @param Carbon $yearStart
     * @param Carbon $yearEnd
     * @param bool $isClosed
     * @return void
     */
    private function createMonthlyPeriods(
        FiscalYear $fiscalYear,
        Carbon $yearStart,
        Carbon $yearEnd,
        bool $isClosed
    ): void {
        $monthNames = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];

        $currentDate = $yearStart->copy();
        $year = $yearStart->year;
        $now = Carbon::now();

        for ($month = 1; $month <= 12; $month++) {
            $periodStart = $currentDate->copy()->startOfMonth();
            $periodEnd = $currentDate->copy()->endOfMonth();

            // Ensure period doesn't exceed fiscal year end
            if ($periodEnd->gt($yearEnd)) {
                $periodEnd = $yearEnd->copy();
            }

            // Determine period status
            $status = $this->determinePeriodStatus($periodEnd, $isClosed, $now);

            FiscalPeriod::updateOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'company_id' => $fiscalYear->company_id,
                    'period_number' => $month,
                ],
                [
                    'name' => "{$monthNames[$month - 1]} {$year}",
                    'start_date' => $periodStart,
                    'end_date' => $periodEnd,
                    'status' => $status,
                    'closed_at' => $status === PeriodStatus::Closed ? $periodEnd->copy()->addDay() : null,
                    'closed_by' => null,
                ]
            );

            $currentDate->addMonth();
        }
    }

    /**
     * Determine the status of a fiscal period based on its end date.
     *
     * Logic:
     * - If fiscal year is closed → all periods are Closed
     * - If period ended more than 1 month ago → Closed
     * - If period ended in the last month → Open (allow adjustments)
     * - If period hasn't ended yet → Open
     *
     * @param Carbon $periodEnd
     * @param bool $fiscalYearClosed
     * @param Carbon $now
     * @return PeriodStatus
     */
    private function determinePeriodStatus(Carbon $periodEnd, bool $fiscalYearClosed, Carbon $now): PeriodStatus
    {
        // If fiscal year is closed, all periods are closed
        if ($fiscalYearClosed) {
            return PeriodStatus::Closed;
        }

        // If period ended more than 1 month ago, close it
        if ($periodEnd->lt($now->copy()->subMonth())) {
            return PeriodStatus::Closed;
        }

        // Otherwise, keep it open
        return PeriodStatus::Open;
    }
}
