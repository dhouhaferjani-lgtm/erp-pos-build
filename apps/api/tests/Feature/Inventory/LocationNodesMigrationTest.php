<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LocationNodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_renamed_with_new_columns(): void
    {
        $this->assertTrue(Schema::hasTable('location_nodes'));
        $this->assertFalse(Schema::hasTable('location_zones'));

        foreach (['parent_id', 'node_type', 'path', 'depth', 'deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('location_nodes', $col), "missing location_nodes.$col");
        }

        $this->assertTrue(Schema::hasTable('product_placements'));
        $this->assertFalse(Schema::hasTable('product_zone_assignments'));
        $this->assertTrue(Schema::hasColumn('product_placements', 'node_id'));
        $this->assertTrue(Schema::hasColumn('product_placements', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('product_placements', 'zone_id'));
    }

    public function test_partial_unique_allows_reassign_after_tombstone(): void
    {
        [$tenantId, $locationId] = $this->seedLocation();
        $nodeA = $this->seedNode($tenantId, $locationId, 'A1');

        $productId = $this->seedProduct($tenantId);

        // one live placement
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));

        // tombstone it
        DB::table('product_placements')->where('product_id', $productId)->update(['deleted_at' => now()]);

        // a second live placement for same (product, location) must now be allowed
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));

        $this->assertSame(1, DB::table('product_placements')
            ->where('product_id', $productId)->whereNull('deleted_at')->count());

        // a THIRD live row must violate the partial unique
        $this->expectException(QueryException::class);
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));
    }

    public function test_partial_unique_allows_code_reuse_after_node_tombstone(): void
    {
        [$tenantId, $locationId] = $this->seedLocation();
        $nodeA = $this->seedNode($tenantId, $locationId, 'A1');

        DB::table('location_nodes')->where('id', $nodeA)->update(['deleted_at' => now()]);

        // same code becomes reusable once the original is tombstoned
        $nodeB = $this->seedNode($tenantId, $locationId, 'A1');
        $this->assertNotSame($nodeA, $nodeB);

        // a second LIVE node with the same code must violate the partial unique
        $this->expectException(QueryException::class);
        $this->seedNode($tenantId, $locationId, 'A1');
    }

    public function test_backfilled_rows_are_top_level_zone_nodes(): void
    {
        [$tenantId, $locationId] = $this->seedLocation();
        $nodeId = $this->seedNode($tenantId, $locationId, 'Z9');

        $row = DB::table('location_nodes')->where('id', $nodeId)->first();
        $this->assertNotNull($row);
        $this->assertSame('zone', $row->node_type);
        $this->assertSame(0, (int) $row->depth);
        $this->assertNull($row->parent_id);
    }

    /**
     * @return array{0: string, 1: string} [tenantId, locationId]
     */
    private function seedLocation(): array
    {
        $tenant = Tenant::create([
            'name' => 'Migration Test Tenant',
            'slug' => 'migration-test-tenant-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Migration Test Company',
            'legal_name' => 'Migration Test Company LLC',
            'tax_id' => 'TAX-MIG-'.Str::upper(Str::random(4)),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-MIG-01',
            'name' => 'Migration Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        return [$tenant->id, $location->id];
    }

    private function seedNode(string $tenantId, string $locationId, string $code): string
    {
        $id = (string) Str::uuid();

        DB::table('location_nodes')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'node_type' => 'zone',
            'name' => 'Node '.$code,
            'code' => $code,
            'path' => $code,
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedProduct(string $tenantId): string
    {
        $companyId = DB::table('companies')->where('tenant_id', $tenantId)->value('id');

        $product = Product::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'sku' => 'MIG-WIDGET-'.Str::upper(Str::random(4)),
            'name' => 'Migration Widget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        return $product->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function placementRow(string $tenantId, string $productId, string $locationId, string $nodeId): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'node_id' => $nodeId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
