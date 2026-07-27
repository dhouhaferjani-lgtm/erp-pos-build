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
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'currency' => 'USD',
        ]);
        $unit = Unit::factory()->create([
            'symbol' => 'kg',
            'decimal_places' => 3,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '1.234')
            ->create([
                'unit_id' => $unit->id,
                'override_unit_price' => '10.129',
            ]);

        $this->actingAs($this->user);

        $this->getJson('/api/v1/workshop/bundles?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.components.0.quantity', '1.234')
            ->assertJsonPath('data.0.components.0.quantity_decimals', 3)
            ->assertJsonPath('data.0.components.0.override_unit_price', '10.12');
    }

    public function test_expansion_endpoint_serializes_part_quantity_precision_from_the_value_object(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
            'currency' => 'USD',
        ]);
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
            ->part($product, '1.234')
            ->create(['unit_id' => $unit->id]);

        $this->actingAs($this->user);

        $this->getJson("/api/v1/workshop/bundles/{$bundle->id}/expansion?qty=1")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', '1.234')
            ->assertJsonPath('data.0.quantity_decimals', 3)
            ->assertJsonPath('data.0.unit_price', '10.00')
            ->assertJsonPath('data.0.line_total', '12.34');
    }

    public function test_index_batch_loads_complete_dto_graph_for_multiple_bundles(): void
    {
        $unit = Unit::factory()->create(['symbol' => 'u', 'decimal_places' => 2]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Batched Part',
        ]);
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Batched Labor',
        ]);
        $nested = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->inactive()->create([
            'name' => 'Batched Nested Bundle',
        ]);

        foreach (range(1, 3) as $index) {
            $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create([
                'code' => "BATCH-{$index}",
                'name' => "Batch Parent {$index}",
            ]);

            ServiceBundleComponent::factory()->forBundle($bundle)->part($product)->create([
                'unit_id' => $unit->id,
                'display_order' => 1,
            ]);
            ServiceBundleComponent::factory()->forBundle($bundle)->labor($service)->create([
                'unit_id' => $unit->id,
                'display_order' => 2,
            ]);
            ServiceBundleComponent::factory()->forBundle($bundle)->nestedBundle($nested)->create([
                'unit_id' => $unit->id,
                'display_order' => 3,
            ]);
            ServiceBundleVehicleApplicability::factory()->forBundle($bundle)->universal()->create();
        }

        $this->actingAs($this->user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/v1/workshop/bundles?active=true&per_page=10');

        $queries = array_values(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount(3, 'data');
        /** @var array<int, array{components: array<int, array{component_display_name: string}>}> $bundles */
        $bundles = $response->json('data');
        foreach ($bundles as $serializedBundle) {
            $this->assertSame(
                ['Batched Part', 'Batched Labor', 'Batched Nested Bundle'],
                collect($serializedBundle['components'])->pluck('component_display_name')->all(),
            );
        }

        foreach ([
            'workshop_service_bundle_components',
            'products',
            'services',
            'units',
            'workshop_service_bundle_vehicle_applicabilities',
        ] as $table) {
            $matchingQueries = array_values(array_filter(
                $queries,
                static fn (array $query): bool => str_contains(strtolower($query['query']), sprintf('from "%s"', $table)),
            ));
            $this->assertCount(1, $matchingQueries, json_encode($queries, JSON_THROW_ON_ERROR));
        }

        $bundleQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "workshop_service_bundles"'),
        ));
        $this->assertCount(3, $bundleQueries, json_encode($queries, JSON_THROW_ON_ERROR));
    }
}
