<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for `GET /api/v1/workshop/bundles/{id}` returning a
 * fully-populated component payload.
 *
 * Each component must expose a non-empty `component_display_name`
 * (resolved from the underlying Part / Labor / NestedBundle entity)
 * and a non-empty `unit` symbol, so the frontend does not need N+1
 * lookups to render.
 */
final class BundleComponentDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->for($this->tenant)->create();

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo(['workshop-bundles.view', 'workshop-bundles.manage']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
    }

    public function test_show_returns_resolved_display_name_and_unit_for_every_component_type(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $nested = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create(['name' => 'Nested Bundle X']);

        $unit = Unit::factory()->create([
            'symbol' => 'u',
            'name' => 'unité',
            'decimal_places' => 3,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Pads Premium',
        ]);

        $laborService = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Inspection',
        ]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '2.000')
            ->create(['unit_id' => $unit->id, 'display_order' => 1]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->labor($laborService, '0.500')
            ->create(['unit_id' => $unit->id, 'display_order' => 2]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->nestedBundle($nested, '1.000')
            ->create(['unit_id' => $unit->id, 'display_order' => 3]);

        $this->actingAs($this->user);

        $response = $this->getJson("/api/v1/workshop/bundles/{$bundle->id}");
        $response->assertOk();

        /** @var array{data: array{components: array<int, array{component_type: string, component_display_name: string, unit: string, quantity_decimals: int}>}} $body */
        $body = $response->json();
        $this->assertCount(3, $body['data']['components']);

        $byType = [];
        foreach ($body['data']['components'] as $component) {
            $byType[$component['component_type']] = $component;
        }

        $this->assertArrayHasKey(BundleComponentType::Part->value, $byType);
        $this->assertSame('Brake Pads Premium', $byType[BundleComponentType::Part->value]['component_display_name']);
        $this->assertNotSame('', $byType[BundleComponentType::Part->value]['unit']);
        $this->assertSame(3, $byType[BundleComponentType::Part->value]['quantity_decimals']);

        $this->assertArrayHasKey(BundleComponentType::Labor->value, $byType);
        $this->assertSame('Brake Inspection', $byType[BundleComponentType::Labor->value]['component_display_name']);
        $this->assertNotSame('', $byType[BundleComponentType::Labor->value]['unit']);
        $this->assertSame(3, $byType[BundleComponentType::Labor->value]['quantity_decimals']);

        $this->assertArrayHasKey(BundleComponentType::NestedBundle->value, $byType);
        $this->assertSame('Nested Bundle X', $byType[BundleComponentType::NestedBundle->value]['component_display_name']);
        $this->assertSame(3, $byType[BundleComponentType::NestedBundle->value]['quantity_decimals']);
    }

    public function test_index_returns_component_quantity_precision_from_its_unit(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create([
            'symbol' => 'kg',
            'decimal_places' => 2,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '1.25')
            ->create(['unit_id' => $unit->id]);

        $this->actingAs($this->user);

        $this->getJson('/api/v1/workshop/bundles?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.components.0.quantity_decimals', 2);
    }

    public function test_expansion_endpoint_serializes_part_quantity_precision_from_the_value_object(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create([
            'symbol' => 'kg',
            'decimal_places' => 3,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
            'sale_price' => '10.000',
        ]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '1.250')
            ->create(['unit_id' => $unit->id]);

        $this->actingAs($this->user);

        $this->getJson("/api/v1/workshop/bundles/{$bundle->id}/expansion?qty=1")
            ->assertOk()
            ->assertJsonPath('data.0.quantity_decimals', 3);
    }
}
