<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Application\Queries\ApplicableBundlesForVehicleQuery;
use App\Modules\Workshop\Bundle\Application\Services\BundleResolutionService;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApplicableBundlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_universal_and_scoped_bundles_for_a_vehicle(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $vehicleId = Str::uuid()->toString();

        $universal = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($universal)->universal()->create();

        $scoped = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($scoped)
            ->forVehicle($vehicleId, VehicleTypeRef::Pc, 'Peugeot 308')->create();

        $other = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($other)
            ->forVehicle(Str::uuid()->toString(), VehicleTypeRef::Pc, 'BMW X5')->create();

        $service = $this->app->make(BundleResolutionService::class);
        $results = $service->applicableForVehicle(new ApplicableBundlesForVehicleQuery(
            tenant_id: $tenant->id,
            company_id: $company->id,
            platform_vehicle_id: $vehicleId,
            vehicle_type: 'pc',
            search: null,
        ));

        $ids = array_map(fn ($b): string => $b->id, $results);
        $this->assertContains($universal->id, $ids);
        $this->assertContains($scoped->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_active_filter_excludes_inactive_bundles(): void
    {
        // Search-by-text filter is integration-tested at the repository
        // level (see BundleRepositoryTest::test_paginate_for_company_filters_by_active_flag).
        // Here we cover the resolution-service path: active filter only.
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();

        $active = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create(['code' => 'ACT-1']);
        ServiceBundleVehicleApplicability::factory()->forBundle($active)->universal()->create();

        $inactive = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->inactive()->create(['code' => 'INA-1']);
        ServiceBundleVehicleApplicability::factory()->forBundle($inactive)->universal()->create();

        $service = $this->app->make(BundleResolutionService::class);
        $results = $service->applicableForVehicle(new ApplicableBundlesForVehicleQuery(
            tenant_id: $tenant->id,
            company_id: $company->id,
            platform_vehicle_id: null,
            vehicle_type: null,
            search: null,
        ));

        $ids = array_map(fn ($b): string => $b->id, $results);
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }
}
