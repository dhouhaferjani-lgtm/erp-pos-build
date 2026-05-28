<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — pre-existing PostgreSQL failure.
 *
 * PlanLimitsService::getUsage() counted locations with
 * `DB::table('locations')->where('tenant_id', ...)`, but the `locations` table
 * is company-scoped (it has `company_id`, no `tenant_id`). On PostgreSQL this
 * throws "column locations.tenant_id does not exist". Usage must count the
 * tenant's locations through their companies.
 */
class PlanLimitsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_counts_locations_through_the_tenants_companies(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        Location::factory()->count(2)->create(['company_id' => $company->id]);

        // A location belonging to a different tenant must not be counted.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        Location::factory()->create(['company_id' => $otherCompany->id]);

        $usage = app(PlanLimitsService::class)->getUsage($tenant);

        $this->assertSame(1, $usage['companies']);
        $this->assertSame(2, $usage['locations']);
    }
}
