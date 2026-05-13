<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Application\Commands\AddComponentCommand;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\Exceptions\BundleCycleException;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CycleDetectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private BundleAuthoringService $service;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->service = $this->app->make(BundleAuthoringService::class);
        $this->unit = Unit::factory()->create();
    }

    public function test_direct_cycle_is_rejected(): void
    {
        $a = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $b = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        // A contains B
        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $a->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $b->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));

        $this->expectException(BundleCycleException::class);

        // B contains A should fail — would create cycle A → B → A.
        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $b->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $a->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));
    }

    public function test_transitive_cycle_is_rejected(): void
    {
        $a = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $b = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $c = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        // A → B
        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $a->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $b->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));
        // B → C
        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $b->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $c->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));

        $this->expectException(BundleCycleException::class);

        // C → A would create A → B → C → A
        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $c->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $a->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));
    }

    public function test_self_reference_is_rejected_at_application_layer(): void
    {
        $a = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $this->expectException(BundleCycleException::class);

        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $a->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $a->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));
    }

    public function test_non_cycle_nesting_is_accepted(): void
    {
        $a = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $b = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $component = $this->service->addComponent(new AddComponentCommand(
            bundle_id: $a->id,
            component_type: BundleComponentType::NestedBundle,
            component_id: $b->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
        ));

        $this->assertInstanceOf(ServiceBundleComponent::class, $component);
        $this->assertSame($b->id, $component->nested_bundle_id);
    }
}
