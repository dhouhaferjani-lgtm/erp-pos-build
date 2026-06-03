<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Application\Commands\AddComponentCommand;
use App\Modules\Workshop\Bundle\Application\Commands\RemoveComponentCommand;
use App\Modules\Workshop\Bundle\Application\Services\BundleAuthoringService;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class AddComponentTest extends TestCase
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

    public function test_add_part_component(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $component = $this->service->addComponent(new AddComponentCommand(
            bundle_id: $bundle->id,
            component_type: BundleComponentType::Part,
            component_id: $product->id,
            quantity: '2.5',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));

        $this->assertSame($product->id, $component->product_id);
        $this->assertNull($component->service_id);
        $this->assertNull($component->nested_bundle_id);
        $this->assertSame(BundleComponentType::Part, $component->component_type);
        $this->assertSame('2.5000', $component->quantity);
    }

    public function test_add_labor_component(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $laborService = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $component = $this->service->addComponent(new AddComponentCommand(
            bundle_id: $bundle->id,
            component_type: BundleComponentType::Labor,
            component_id: $laborService->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));

        $this->assertSame($laborService->id, $component->service_id);
        $this->assertNull($component->product_id);
        $this->assertNull($component->nested_bundle_id);
    }

    public function test_quantity_must_be_positive(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->addComponent(new AddComponentCommand(
            bundle_id: $bundle->id,
            component_type: BundleComponentType::Part,
            component_id: $product->id,
            quantity: '0', // invalid
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));
    }

    public function test_remove_component_deletes_row(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $component = $this->service->addComponent(new AddComponentCommand(
            bundle_id: $bundle->id,
            component_type: BundleComponentType::Part,
            component_id: $product->id,
            quantity: '1.000',
            unit_id: $this->unit->id,
            override_unit_price: null,
            is_optional: false,
            display_order: 1,
            notes: null,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));

        $this->service->removeComponent(new RemoveComponentCommand(
            bundle_id: $bundle->id,
            component_id: $component->id,
            tenant_id: $bundle->tenant_id,
            company_id: $bundle->company_id,
        ));

        $this->assertDatabaseMissing('workshop_service_bundle_components', ['id' => $component->id]);
    }
}
