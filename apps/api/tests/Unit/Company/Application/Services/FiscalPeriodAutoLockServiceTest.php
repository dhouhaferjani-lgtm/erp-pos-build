<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Application\Services;

use App\Modules\Company\Application\Services\CountryFiscalRulesProvider;
use App\Modules\Company\Application\Services\FiscalPeriodAutoLockService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalPeriodAutoLockServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriodAutoLockService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start_month' => 1,
        ]);

        $rulesProvider = app(CountryFiscalRulesProvider::class);
        $this->service = new FiscalPeriodAutoLockService($rulesProvider);
    }

    public function test_it_locks_periods_ended_more_than_one_month_ago(): void
    {
        // Given: A fiscal year (not closed) with a period that ended 2 months ago
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $oldPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'October 2024',
            'period_number' => 10,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Period status changes to Closed
        $oldPeriod->refresh();
        $this->assertEquals(PeriodStatus::Closed, $oldPeriod->status);
    }

    public function test_it_does_not_lock_periods_ended_less_than_one_month_ago(): void
    {
        // Given: A fiscal year with a period that ended 15 days ago
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $recentPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Recent Period',
            'period_number' => 11,
            'start_date' => Carbon::now()->subDays(45)->startOfMonth(),
            'end_date' => Carbon::now()->subDays(15)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Period remains Open
        $recentPeriod->refresh();
        $this->assertEquals(PeriodStatus::Open, $recentPeriod->status);
    }

    public function test_it_does_not_lock_current_or_future_periods(): void
    {
        // Given: A fiscal year with current and future periods
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $currentPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Current Period',
            'period_number' => 12,
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $futurePeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Future Period',
            'period_number' => 1,
            'start_date' => Carbon::now()->addMonth()->startOfMonth(),
            'end_date' => Carbon::now()->addMonth()->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Both periods remain Open
        $currentPeriod->refresh();
        $futurePeriod->refresh();
        $this->assertEquals(PeriodStatus::Open, $currentPeriod->status);
        $this->assertEquals(PeriodStatus::Open, $futurePeriod->status);
    }

    public function test_it_marks_fiscal_years_as_closed_when_ended(): void
    {
        // Given: A fiscal year that has ended
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2023',
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->endOfYear(),
            'is_closed' => false,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Fiscal year is marked as closed
        $fiscalYear->refresh();
        $this->assertTrue($fiscalYear->is_closed);
    }

    public function test_it_locks_all_periods_in_closed_fiscal_years(): void
    {
        // Given: A closed fiscal year with mixed period statuses
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2023',
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->endOfYear(),
            'is_closed' => true,
        ]);

        $openPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Period 1',
            'period_number' => 1,
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->startOfYear()->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $closedPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Period 2',
            'period_number' => 2,
            'start_date' => Carbon::now()->subYears(2)->startOfYear()->addMonth(),
            'end_date' => Carbon::now()->subYears(2)->startOfYear()->addMonth()->endOfMonth(),
            'status' => PeriodStatus::Closed,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: All periods in closed fiscal year become Locked
        $openPeriod->refresh();
        $closedPeriod->refresh();
        $this->assertEquals(PeriodStatus::Locked, $openPeriod->status);
        $this->assertEquals(PeriodStatus::Locked, $closedPeriod->status);
    }

    public function test_it_does_not_modify_already_locked_periods(): void
    {
        // Given: A period that is already locked
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2023',
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->endOfYear(),
            'is_closed' => true,
        ]);

        $lockedPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Locked Period',
            'period_number' => 1,
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->startOfYear()->endOfMonth(),
            'status' => PeriodStatus::Locked,
        ]);

        $updatedAt = $lockedPeriod->updated_at;

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Locked period unchanged
        $lockedPeriod->refresh();
        $this->assertEquals(PeriodStatus::Locked, $lockedPeriod->status);
        $this->assertEquals($updatedAt->timestamp, $lockedPeriod->updated_at->timestamp);
    }

    public function test_it_handles_multiple_companies_correctly(): void
    {
        // Given: Two companies with old periods
        $company2 = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start_month' => 1,
        ]);

        $fiscalYear1 = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $fiscalYear2 = FiscalYear::create([
            'company_id' => $company2->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $period1 = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear1->id,
            'company_id' => $this->company->id,
            'name' => 'Period Company 1',
            'period_number' => 9,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $period2 = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear2->id,
            'company_id' => $company2->id,
            'name' => 'Period Company 2',
            'period_number' => 9,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Both companies' periods are locked
        $period1->refresh();
        $period2->refresh();
        $this->assertEquals(PeriodStatus::Closed, $period1->status);
        $this->assertEquals(PeriodStatus::Closed, $period2->status);
    }

    public function test_it_handles_companies_with_no_fiscal_years_gracefully(): void
    {
        // Given: A company with no fiscal years
        $emptyCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Delete any auto-created fiscal years
        $emptyCompany->fiscalYears()->delete();

        // When: Auto-lock service runs
        // Then: No exception is thrown
        $this->expectNotToPerformAssertions();
        $this->service->lockExpiredPeriods();
    }

    public function test_it_uses_country_specific_period_lock_threshold(): void
    {
        // Given: A company with Tunisia rules (1 month threshold)
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);

        // Create fiscal year and period that ended 2 months ago
        $fiscalYear = FiscalYear::create([
            'company_id' => $company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $company->id,
            'name' => 'Old Period',
            'period_number' => 10,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(2),  // 2 months old
            'status' => PeriodStatus::Open,
        ]);

        // When: Auto-lock service runs
        $this->service->lockExpiredPeriods();

        // Then: Period locked because 2 months > 1 month threshold
        $period->refresh();
        $this->assertEquals(PeriodStatus::Closed, $period->status);
    }

    // ── Session B lane Q-10 (a): per-company country resolution ─────────
    //
    // Pre-fix, lockOldPeriods() called getRulesForCountry('TN') ONCE, with no
    // company or country predicate, and applied Tunisia's window to every Open
    // period in the tenant. These pin that the window is now resolved per
    // company, and that a company whose country has NO dedicated fiscal rules
    // is SKIPPED (fail safe) rather than silently locked on Tunisia's calendar.

    public function test_it_skips_companies_whose_country_has_no_dedicated_fiscal_rules(): void
    {
        // Given: a German company (CountryFiscalRulesProvider has no getRulesForDE)
        $germanCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'DE',
            'fiscal_year_start_month' => 1,
        ]);

        $fiscalYear = FiscalYear::create([
            'company_id' => $germanCompany->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $germanCompany->id,
            'name' => 'Old German Period',
            'period_number' => 3,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        // When
        $this->service->lockExpiredPeriods();

        // Then: untouched — Tunisia's threshold is NOT its threshold
        $period->refresh();
        $this->assertEquals(PeriodStatus::Open, $period->status);
        $this->assertNull($period->status_actor);
    }

    public function test_it_skips_companies_with_a_blank_country_code(): void
    {
        $blankCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => '  ',
            'fiscal_year_start_month' => 1,
        ]);

        $fiscalYear = FiscalYear::create([
            'company_id' => $blankCompany->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $blankCompany->id,
            'name' => 'Old Period',
            'period_number' => 4,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $this->service->lockExpiredPeriods();

        $period->refresh();
        $this->assertEquals(PeriodStatus::Open, $period->status);
    }

    public function test_it_does_not_close_fiscal_years_of_skipped_companies(): void
    {
        // The fail-safe skip must cover ALL THREE steps: closing the year for a
        // skipped company would re-enter STEP 3 and Lock its periods anyway.
        $germanCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'DE',
            'fiscal_year_start_month' => 1,
        ]);

        $endedYear = FiscalYear::create([
            'company_id' => $germanCompany->id,
            'name' => '2023',
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->endOfYear(),
            'is_closed' => false,
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $endedYear->id,
            'company_id' => $germanCompany->id,
            'name' => 'Period 1',
            'period_number' => 1,
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->startOfYear()->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $this->service->lockExpiredPeriods();

        $endedYear->refresh();
        $period->refresh();
        $this->assertFalse($endedYear->is_closed);
        $this->assertEquals(PeriodStatus::Open, $period->status);
    }

    public function test_it_resolves_the_lock_window_per_company_country(): void
    {
        // A TN company and an FR company side by side: BOTH must be evaluated on
        // their OWN seeded rules (both currently 1 month), and a DE company in
        // the same tenant must be left alone in the same pass.
        $tnCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);
        $frCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'fiscal_year_start_month' => 1,
        ]);
        $deCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'DE',
            'fiscal_year_start_month' => 1,
        ]);

        $periods = [];
        foreach ([$tnCompany, $frCompany, $deCompany] as $i => $company) {
            $year = FiscalYear::create([
                'company_id' => $company->id,
                'name' => '2025',
                'start_date' => Carbon::now()->startOfYear(),
                'end_date' => Carbon::now()->endOfYear(),
                'is_closed' => false,
            ]);

            $periods[$company->country_code] = FiscalPeriod::create([
                'fiscal_year_id' => $year->id,
                'company_id' => $company->id,
                'name' => 'Old Period '.$i,
                'period_number' => 5 + $i,
                'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
                'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
                'status' => PeriodStatus::Open,
            ]);
        }

        $this->service->lockExpiredPeriods();

        $this->assertEquals(PeriodStatus::Closed, $periods['TN']->refresh()->status);
        $this->assertEquals(PeriodStatus::Closed, $periods['FR']->refresh()->status);
        $this->assertEquals(PeriodStatus::Open, $periods['DE']->refresh()->status);
    }

    // ── Session B lane Q-10 (b): per-period audit stamps ────────────────

    public function test_it_stamps_per_period_audit_columns_when_closing(): void
    {
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2025',
            'start_date' => Carbon::now()->startOfYear(),
            'end_date' => Carbon::now()->endOfYear(),
            'is_closed' => false,
        ]);

        $period = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Old Period',
            'period_number' => 8,
            'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
            'end_date' => Carbon::now()->subMonths(3)->endOfMonth(),
            'status' => PeriodStatus::Open,
        ]);

        $this->service->lockExpiredPeriods();

        $period->refresh();
        $this->assertEquals(PeriodStatus::Closed, $period->status);
        $this->assertNotNull($period->closed_at);
        $this->assertNull($period->closed_by, 'the nightly scheduler is not a user');
        $this->assertSame('system:auto-lock', $period->status_actor);
        $this->assertSame(PeriodStatus::Open->value, $period->status_changed_from);
    }

    public function test_it_stamps_per_period_audit_columns_when_locking(): void
    {
        $fiscalYear = FiscalYear::create([
            'company_id' => $this->company->id,
            'name' => '2023',
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->endOfYear(),
            'is_closed' => true,
        ]);

        $closedPeriod = FiscalPeriod::create([
            'fiscal_year_id' => $fiscalYear->id,
            'company_id' => $this->company->id,
            'name' => 'Period 1',
            'period_number' => 1,
            'start_date' => Carbon::now()->subYears(2)->startOfYear(),
            'end_date' => Carbon::now()->subYears(2)->startOfYear()->endOfMonth(),
            'status' => PeriodStatus::Closed,
        ]);

        $this->service->lockExpiredPeriods();

        $closedPeriod->refresh();
        $this->assertEquals(PeriodStatus::Locked, $closedPeriod->status);
        $this->assertNotNull($closedPeriod->locked_at);
        $this->assertNull($closedPeriod->locked_by, 'the nightly scheduler is not a user');
        $this->assertSame('system:auto-lock', $closedPeriod->status_actor);
        $this->assertSame(PeriodStatus::Closed->value, $closedPeriod->status_changed_from);
    }
}
