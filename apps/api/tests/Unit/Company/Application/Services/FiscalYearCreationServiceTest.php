<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Application\Services;

use App\Modules\Company\Application\Services\CountryFiscalRulesProvider;
use App\Modules\Company\Application\Services\FiscalYearCreationService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalYearCreationService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FiscalYearCreationService(
            new CountryFiscalRulesProvider
        );
        $this->tenant = Tenant::factory()->create();
    }

    public function test_it_creates_three_fiscal_years(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);

        // Clear any fiscal years created by event (to test service in isolation)
        $company->fiscalYears()->delete();

        $this->service->createFiscalYearsForCompany($company);

        $this->assertCount(3, $company->fresh()->fiscalYears);
    }

    public function test_it_creates_12_periods_per_year(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);

        $this->service->createFiscalYearsForCompany($company);

        foreach ($company->fresh()->fiscalYears as $year) {
            $this->assertCount(12, $year->periods);
        }
    }

    public function test_it_respects_custom_fiscal_start_month(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'fiscal_year_start_month' => 7,
        ]);

        $this->service->createFiscalYearsForCompany($company);

        $currentYear = $company->fresh()->fiscalYears()
            ->where('name', (string) date('Y'))
            ->orWhere('name', (string) (date('Y') - 1))
            ->first();

        $this->assertNotNull($currentYear);
        $this->assertEquals(7, $currentYear->start_date->month);
    }

    public function test_it_creates_years_with_correct_boundaries(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);

        $this->service->createFiscalYearsForCompany($company);

        $fiscalYear = $company->fresh()->fiscalYears->first();

        $this->assertNotNull($fiscalYear);

        // Fiscal year should be 12 months
        $daysDifference = $fiscalYear->start_date->diffInDays($fiscalYear->end_date);
        $this->assertGreaterThanOrEqual(364, $daysDifference);
        $this->assertLessThanOrEqual(366, $daysDifference);
    }

    public function test_it_assigns_correct_period_status(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);

        $this->service->createFiscalYearsForCompany($company);

        $allPeriods = $company->fresh()->fiscalYears->flatMap(fn ($year) => $year->periods);

        // At least some periods should exist
        $this->assertGreaterThan(0, $allPeriods->count());

        // All periods should have a valid status
        foreach ($allPeriods as $period) {
            $this->assertInstanceOf(PeriodStatus::class, $period->status);
        }
    }

    public function test_it_creates_periods_with_sequential_numbers(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
        ]);

        $this->service->createFiscalYearsForCompany($company);

        $fiscalYear = $company->fresh()->fiscalYears->first();
        $periods = $fiscalYear->periods()->orderBy('period_number')->get();

        $this->assertCount(12, $periods);

        for ($i = 1; $i <= 12; $i++) {
            $this->assertEquals($i, $periods[$i - 1]->period_number);
        }
    }

    public function test_it_sets_correct_fiscal_year_names(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);

        // Clear any fiscal years created by event (to test service in isolation)
        $company->fiscalYears()->delete();

        $this->service->createFiscalYearsForCompany($company);

        $fiscalYears = $company->fresh()->fiscalYears()
            ->orderBy('start_date')
            ->get();

        $this->assertCount(3, $fiscalYears);

        // Names should be years (e.g., "2024", "2025", "2026")
        foreach ($fiscalYears as $year) {
            $this->assertMatchesRegularExpression('/^\d{4}$/', $year->name);
        }
    }

    public function test_it_handles_tunisia_rules(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);

        // Clear any fiscal years created by event (to test service in isolation)
        $company->fiscalYears()->delete();

        $this->service->createFiscalYearsForCompany($company);

        $fiscalYears = $company->fresh()->fiscalYears;

        $this->assertCount(3, $fiscalYears);

        // All fiscal years should start in January for Tunisia
        foreach ($fiscalYears as $year) {
            $this->assertEquals(1, $year->start_date->month);
        }
    }

    public function test_it_handles_france_rules(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'fiscal_year_start_month' => 4, // April start (non-standard)
        ]);

        // Clear any fiscal years created by event (to test service in isolation)
        $company->fiscalYears()->delete();

        $this->service->createFiscalYearsForCompany($company);

        $fiscalYears = $company->fresh()->fiscalYears;

        $this->assertCount(3, $fiscalYears);

        // All fiscal years should start in April
        foreach ($fiscalYears as $year) {
            $this->assertEquals(4, $year->start_date->month);
        }
    }
}
