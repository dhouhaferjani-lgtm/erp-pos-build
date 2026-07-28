<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BundleLineSerializationQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_bundle_batch_loads_product_units_before_serializing_multiple_lines(): void
    {
        $tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $company = Company::factory()->for($tenant)->create();

        Permission::findOrCreate('work-orders.update', 'sanctum');
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $user = User::factory()->for($tenant)->create();
        $user->givePermissionTo('work-orders.update');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
        Sanctum::actingAs($user, ['tenant:'.$tenant->id]);

        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $bundle = ServiceBundle::factory()->forCompany($tenant->id, $company->id)->create();
        $firstUnit = Unit::factory()->create(['decimal_places' => 3]);
        $secondUnit = Unit::factory()->create(['decimal_places' => 2]);
        $firstProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'unit_id' => $firstUnit->id,
            'sale_price' => '10.000',
        ]);
        $secondProduct = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'unit_id' => $secondUnit->id,
            'sale_price' => '20.000',
        ]);

        ServiceBundleComponent::factory()->forBundle($bundle)->part($firstProduct, '1.250')->create([
            'unit_id' => $firstUnit->id,
            'display_order' => 0,
        ]);
        ServiceBundleComponent::factory()->forBundle($bundle)->part($secondProduct, '2.50')->create([
            'unit_id' => $secondUnit->id,
            'display_order' => 1,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson("/api/v1/workshop/work-orders/{$workOrder->id}/lines/bundle", [
            'bundle_id' => $bundle->id,
            'quantity' => '1',
        ]);

        $queries = array_values(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertCreated()
            ->assertJsonPath('data.0.quantity_decimals', 3)
            ->assertJsonPath('data.1.quantity_decimals', 2);

        $lastWorkOrderUpdate = null;
        foreach ($queries as $index => $query) {
            if (str_contains(strtolower($query['query']), 'update "workshop_work_orders"')) {
                $lastWorkOrderUpdate = $index;
            }
        }

        $this->assertNotNull($lastWorkOrderUpdate, 'Expected the bundle service to recompute work-order totals.');
        $serializationQueries = array_slice($queries, $lastWorkOrderUpdate + 1);
        $productQueries = array_values(array_filter(
            $serializationQueries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "products"'),
        ));
        $unitQueries = array_values(array_filter(
            $serializationQueries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'from "units"'),
        ));

        $this->assertCount(1, $productQueries, json_encode($serializationQueries, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $unitQueries, json_encode($serializationQueries, JSON_THROW_ON_ERROR));
        $this->assertCount(2, $productQueries[0]['bindings']);
        $this->assertCount(2, $unitQueries[0]['bindings']);
    }
}
