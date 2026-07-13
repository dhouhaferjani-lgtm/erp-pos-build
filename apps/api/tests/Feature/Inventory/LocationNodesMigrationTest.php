<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Migrations\Migration;
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

    public function test_runtime_references_and_generated_contract_use_hierarchy_names(): void
    {
        $this->assertSame('location_nodes', (new LocationNode)->getTable());
        $this->assertSame('product_placements', (new ProductPlacement)->getTable());

        $runtimeFiles = [
            app_path('Modules/Inventory/Application/DTOs/LocationNodeDto.php'),
            app_path('Modules/Inventory/Application/DTOs/ProductPlacementDto.php'),
            app_path('Modules/Inventory/Application/Services/CountingBlockService.php'),
            app_path('Modules/Inventory/Application/Services/InventoryCountingService.php'),
            app_path('Modules/Inventory/Presentation/Controllers/InventoryCountingController.php'),
            app_path('Modules/Inventory/Presentation/Requests/CreateCountingRequest.php'),
        ];

        foreach ($runtimeFiles as $runtimeFile) {
            $source = file_get_contents($runtimeFile);
            $this->assertIsString($source, "Unable to read runtime reference {$runtimeFile}");
            $this->assertStringNotContainsString('location_zones', $source, $runtimeFile);
            $this->assertStringNotContainsString('product_zone_assignments', $source, $runtimeFile);
            $this->assertDoesNotMatchRegularExpression('/[\'\"]zone_id[\'\"]/', $source, $runtimeFile);
        }

        $generated = file_get_contents(base_path('../../packages/shared/types/generated.d.ts'));
        $this->assertIsString($generated);
        $this->assertStringContainsString('export type LocationNodeDto = {', $generated);
        $this->assertStringContainsString('export type ProductPlacementDto = {', $generated);
        $this->assertMatchesRegularExpression('/export type ProductPlacementDto = \{[^}]*node_id: string;/s', $generated);
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
     * Data survival (review IMPORTANT-1): the backfill UPDATE must run
     * against PRE-EXISTING flat-zone data, not just fresh rows. Drive the
     * real migration down, plant a legacy location_zones row + a
     * product_zone_assignments row referencing it, migrate up, and assert
     * both survived the rename + backfill.
     */
    public function test_up_backfills_legacy_zone_rows_and_preserves_placements(): void
    {
        [$tenantId, $locationId] = $this->seedLocation();
        $productId = $this->seedProduct($tenantId);

        $migration = require database_path('migrations/tenant/2026_07_07_100001_rename_zones_to_location_nodes.php');
        $this->assertInstanceOf(Migration::class, $migration);
        (new \ReflectionMethod($migration, 'down'))->invoke($migration);

        $this->assertTrue(Schema::hasTable('location_zones'));
        $this->assertFalse(Schema::hasTable('location_nodes'));

        // Legacy flat-zone rows, exactly as the pre-hierarchy schema wrote them.
        $zoneId = (string) Str::uuid();
        DB::table('location_zones')->insert([
            'id' => $zoneId,
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => 'Legacy Aisle 1',
            'code' => 'A1',
            'sort_order' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('product_zone_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'zone_id' => $zoneId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \ReflectionMethod($migration, 'up'))->invoke($migration);

        // The zone survived as a top-level node with the backfilled hierarchy columns.
        $node = DB::table('location_nodes')->where('id', $zoneId)->first();
        $this->assertNotNull($node, 'legacy zone row must survive the rename');
        $this->assertSame('zone', $node->node_type);
        $this->assertSame(0, (int) $node->depth);
        $this->assertSame('A1', $node->path, 'path must be backfilled from code');
        $this->assertNull($node->parent_id);
        $this->assertNull($node->deleted_at);
        $this->assertSame('Legacy Aisle 1', $node->name);
        $this->assertSame(3, (int) $node->sort_order);

        // The assignment survived as a live placement still pointing at the node.
        $placement = DB::table('product_placements')->where('id', $assignmentId)->first();
        $this->assertNotNull($placement, 'legacy assignment row must survive the rename');
        $this->assertSame($productId, $placement->product_id);
        $this->assertSame($locationId, $placement->location_id);
        $this->assertSame($zoneId, $placement->node_id, 'zone_id must carry over into node_id');
        $this->assertNull($placement->deleted_at);
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
