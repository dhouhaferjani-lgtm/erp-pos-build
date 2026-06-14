<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\StockDistributionRowDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockDistributionQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $shop;

    private Location $warehouse;

    private Location $office;

    private Location $closedShop;

    private Product $product;

    private LocationStockQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Dist Tenant',
            'slug' => 'dist-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme',
            'legal_name' => 'Acme LLC',
            'tax_id' => 'TAX-ACME',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // 'Shop A' (active shop) — the current location.
        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-A',
            'name' => 'Shop A',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        // 'WH' (active warehouse).
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'WH',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // 'HQ' (active office) — must be EXCLUDED (not shop/warehouse).
        $this->office = Location::create([
            'company_id' => $this->company->id,
            'code' => 'HQ-01',
            'name' => 'HQ',
            'type' => 'office',
            'is_active' => true,
            'is_default' => false,
        ]);

        // 'Closed' (inactive shop) — must be EXCLUDED.
        $this->closedShop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-X',
            'name' => 'Closed',
            'type' => 'shop',
            'is_active' => false,
            'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'WIDGET',
            'name' => 'Widget',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->service = app(LocationStockQueryService::class);
    }

    private function seedStock(Location $loc, string $quantity, string $reserved): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $loc->id,
            'quantity' => $quantity,
            'reserved' => $reserved,
        ]);
    }

    private function seedInTransitTransferTo(Location $destination, string $quantity): void
    {
        $transferId = (string) Str::uuid();
        DB::table('stock_transfers')->insert([
            'id' => $transferId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'transfer_number' => 'TR-'.Str::random(8),
            'transfer_type' => 'intracompany',
            'status' => TransferStatus::InTransit->value,
            'source_location_id' => $this->warehouse->id,
            'destination_location_id' => $destination->id,
            'initiated_by_user_id' => $this->makeUserId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_transfer_lines')->insert([
            'id' => (string) Str::uuid(),
            'transfer_id' => $transferId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUserId(): string
    {
        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'tenant_id' => $this->tenant->id,
            'name' => 'U',
            'email' => 'u-'.Str::random(6).'@e.com',
            'password' => bcrypt('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    public function test_returns_on_hand_and_in_transit_per_shop_and_warehouse(): void
    {
        // shop: qty 12 reserved 2 -> available 10 ; warehouse: qty 40 reserved 0 -> 40.
        $this->seedStock($this->shop, '12', '2');
        $this->seedStock($this->warehouse, '40', '0');
        // in-transit transfer of 5 to the shop.
        $this->seedInTransitTransferTo($this->shop, '5');

        $dto = $this->service->stockDistributionForProduct(
            $this->tenant->id,
            $this->company->id,
            $this->product->id,
            null,
            $this->shop->id,
        );

        // Office + inactive excluded: exactly 2 rows.
        $this->assertCount(2, $dto->locations);

        // Current (shop) first, then warehouse by name.
        /** @var StockDistributionRowDTO $first */
        $first = $dto->locations[0];
        /** @var StockDistributionRowDTO $second */
        $second = $dto->locations[1];

        $this->assertSame($this->shop->id, $first->locationId);
        $this->assertTrue($first->isCurrent);
        $this->assertSame('shop', $first->locationType);
        $this->assertSame('10.0000', $first->onHand);
        $this->assertSame('5.0000', $first->incomingTransfer);

        $this->assertSame($this->warehouse->id, $second->locationId);
        $this->assertFalse($second->isCurrent);
        $this->assertSame('warehouse', $second->locationType);
        $this->assertSame('40.0000', $second->onHand);
        $this->assertSame('0.0000', $second->incomingTransfer);

        $this->assertSame('50.0000', $dto->totalOnHand);
        $this->assertSame('5.0000', $dto->totalIncomingTransfer);

        $this->assertSame($this->product->id, $dto->productId);
        $this->assertNull($dto->variantId);
    }
}
