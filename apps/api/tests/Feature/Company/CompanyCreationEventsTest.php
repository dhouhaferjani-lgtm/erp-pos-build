<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Company\Listeners\CreateFiscalYearsForNewCompany;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompanyCreationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_company_created_event_when_company_is_created(): void
    {
        Event::fake([CompanyCreated::class]);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
        ]);

        Event::assertDispatched(CompanyCreated::class, function ($event) use ($company) {
            return $event->companyId === $company->id
                && $event->tenantId === $company->tenant_id
                && $event->countryCode === $company->country_code;
        });
    }

    public function test_it_creates_fiscal_years_automatically_when_company_is_created(): void
    {
        // Don't fake events - let them run naturally
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
        ]);

        // Should have 3 fiscal years automatically created
        $this->assertCount(3, $company->fresh()->fiscalYears);

        // Each fiscal year should have 12 periods
        foreach ($company->fresh()->fiscalYears as $year) {
            $this->assertCount(12, $year->periods);
        }
    }

    public function test_it_creates_fiscal_years_based_on_company_country(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        // France company
        $franceCo = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'FR',
            'fiscal_year_start_month' => 1,
        ]);

        // Tunisia company
        $tunisiaCo = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
            'fiscal_year_start_month' => 1,
        ]);

        // Both should have fiscal years
        $this->assertCount(3, $franceCo->fresh()->fiscalYears);
        $this->assertCount(3, $tunisiaCo->fresh()->fiscalYears);

        // All fiscal years should start in January
        foreach ($franceCo->fresh()->fiscalYears as $year) {
            $this->assertEquals(1, $year->start_date->month);
        }

        foreach ($tunisiaCo->fresh()->fiscalYears as $year) {
            $this->assertEquals(1, $year->start_date->month);
        }
    }

    public function test_listener_handles_company_created_event(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'FR',
        ]);

        // Create the event
        $event = new CompanyCreated(
            companyId: $company->id,
            tenantId: $company->tenant_id,
            name: $company->name,
            countryCode: $company->country_code,
            currency: $company->currency,
            fiscalYearStartMonth: $company->fiscal_year_start_month,
            createdBy: $user->id,
            createdAt: $company->created_at->toIso8601String(),
        );

        // Delete any existing fiscal years (to test listener creates them)
        $company->fiscalYears()->delete();
        $this->assertCount(0, $company->fresh()->fiscalYears);

        // Manually invoke the listener
        $listener = app(CreateFiscalYearsForNewCompany::class);
        $listener->handle($event);

        // Fiscal years should be created
        $this->assertCount(3, $company->fresh()->fiscalYears);
    }

    public function test_listener_gracefully_handles_errors(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Create event with non-existent company ID
        $event = new CompanyCreated(
            companyId: 'non-existent-id',
            tenantId: $tenant->id,
            name: 'Test Company',
            countryCode: 'FR',
            currency: 'EUR',
            fiscalYearStartMonth: 1,
            createdBy: $user->id,
            createdAt: now()->toIso8601String(),
        );

        $listener = app(CreateFiscalYearsForNewCompany::class);

        // Should not throw exception
        $listener->handle($event);

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }
}
