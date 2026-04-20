<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Contracts\BundleRepositoryInterface;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BundleRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private BundleRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->repository = $this->app->make(BundleRepositoryInterface::class);
    }

    public function test_find_with_components_and_applicabilities_eager_loads_relations(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($bundle)->universal()->create();

        $loaded = $this->repository->findWithComponentsAndApplicabilities($bundle->id);

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->relationLoaded('components'));
        $this->assertTrue($loaded->relationLoaded('vehicleApplicabilities'));
        $this->assertCount(1, $loaded->vehicleApplicabilities);
    }

    public function test_applicable_for_vehicle_returns_universal_and_matching(): void
    {
        $vehicleId = Str::uuid()->toString();

        $universal = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($universal)->universal()->create();

        $matching = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()
            ->forBundle($matching)
            ->forVehicle($vehicleId, VehicleTypeRef::Pc, 'Peugeot 308')
            ->create();

        $otherVehicle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()
            ->forBundle($otherVehicle)
            ->forVehicle(Str::uuid()->toString(), VehicleTypeRef::Pc, 'BMW X5')
            ->create();

        $results = $this->repository->applicableForVehicle(
            $this->tenant->id,
            $this->company->id,
            $vehicleId,
            'pc',
            null,
        );

        $ids = $results->pluck('id')->all();
        $this->assertContains($universal->id, $ids);
        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($otherVehicle->id, $ids);
    }

    public function test_applicable_for_vehicle_without_vehicle_returns_only_universal(): void
    {
        $universal = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()->forBundle($universal)->universal()->create();

        $vehicleScoped = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()
            ->forBundle($vehicleScoped)
            ->forVehicle(Str::uuid()->toString(), VehicleTypeRef::Pc)
            ->create();

        $results = $this->repository->applicableForVehicle(
            $this->tenant->id,
            $this->company->id,
            null,
            null,
            null,
        );

        $ids = $results->pluck('id')->all();
        $this->assertContains($universal->id, $ids);
        $this->assertNotContains($vehicleScoped->id, $ids);
    }

    public function test_paginate_for_company_filters_by_active_flag(): void
    {
        ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create(['code' => 'ACTIVE-1']);
        ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->inactive()->create(['code' => 'INACTIVE-1']);

        $activeOnly = $this->repository->paginateForCompany(
            $this->tenant->id,
            $this->company->id,
            ['active' => true],
            perPage: 10,
        );

        $this->assertSame(1, $activeOnly->total());
    }
}
