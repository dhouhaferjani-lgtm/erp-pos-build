<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Exceptions\VariantRequiredException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAdjustmentServiceVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Product $product;

    private string $userId;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Variant Tenant',
            'slug' => 'variant-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Variant Company',
            'legal_name' => 'Variant Company LLC',
            'tax_id' => 'TAXV1',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-V1',
            'name' => 'Variant Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-V-001',
            'name' => 'Variant Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Variant User',
            'email' => 'variant-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->userId = $user->id;

        $this->service = app(StockAdjustmentService::class);
    }

    private function makeVariant(bool $isActive = true): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_code' => 'VAR-'.Str::upper(Str::random(6)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'barcode' => null,
            'name_suffix' => 'Red / XL',
            'is_default' => false,
            'is_active' => $isActive,
            'display_order' => 0,
            'price_override' => null,
            'cost_override' => null,
            'image_url' => null,
        ]);
    }

    public function test_receive_variant_creates_variant_scoped_stock_level(): void
    {
        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'PO-V-001',
            userId: $this->userId,
            variantId: $variant->id,
        );

        $level = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($level);
        $this->assertEquals('5.00', $level->quantity);
        $this->assertSame($variant->id, $level->variant_id);

        // The stock_movements ROW carries the variant_id.
        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame($variant->id, $movement->variant_id);
    }

    public function test_receive_without_variant_creates_product_scoped_level_when_no_variants(): void
    {
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '7.00',
            reference: 'PO-V-002',
            userId: $this->userId,
        );

        $level = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->whereNull('variant_id')
            ->first();

        $this->assertNotNull($level);
        $this->assertEquals('7.00', $level->quantity);
        $this->assertNull($level->variant_id);
    }

    public function test_receive_without_variant_on_variant_bearing_product_raises(): void
    {
        // Product has an active variant, but caller addresses the product level.
        $this->makeVariant();

        $this->expectException(VariantRequiredException::class);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '3.00',
            reference: 'PO-V-003',
            userId: $this->userId,
        );
    }

    public function test_receive_without_variant_when_only_inactive_variants_is_allowed(): void
    {
        // Inactive variants do not force variant addressing.
        $this->makeVariant(isActive: false);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '4.00',
            reference: 'PO-V-004',
            userId: $this->userId,
        );

        $level = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->whereNull('variant_id')
            ->first();

        $this->assertNotNull($level);
        $this->assertEquals('4.00', $level->quantity);
    }

    public function test_issue_variant_scoped(): void
    {
        $variant = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-V-005',
            userId: $this->userId,
            variantId: $variant->id,
        );

        $this->service->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '4.00',
            reference: 'SO-V-005',
            userId: $this->userId,
            variantId: $variant->id,
        );

        $level = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($level);
        $this->assertEquals('6.00', $level->quantity);
    }

    public function test_two_variants_keep_separate_stock_levels(): void
    {
        $variantA = $this->makeVariant();
        $variantB = $this->makeVariant();

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.00',
            reference: 'PO-A',
            userId: $this->userId,
            variantId: $variantA->id,
        );
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '8.00',
            reference: 'PO-B',
            userId: $this->userId,
            variantId: $variantB->id,
        );

        $levelA = StockLevel::query()
            ->where('variant_id', $variantA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $levelB = StockLevel::query()
            ->where('variant_id', $variantB->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($levelA);
        $this->assertNotNull($levelB);
        $this->assertNotSame($levelA->id, $levelB->id);
        $this->assertEquals('5.00', $levelA->quantity);
        $this->assertEquals('8.00', $levelB->quantity);
    }

    public function test_adjust_variant_scoped(): void
    {
        $variant = $this->makeVariant();

        $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            newQuantity: '12.00',
            reason: 'count',
            userId: $this->userId,
            variantId: $variant->id,
        );

        $level = StockLevel::query()
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($level);
        $this->assertEquals('12.00', $level->quantity);
    }

    public function test_transfer_variant_moves_variant_scoped_stock(): void
    {
        $variant = $this->makeVariant();

        $toLocation = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-V2',
            'name' => 'Variant Warehouse 2',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Seed variant-scoped stock at the from-location.
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.00',
            reference: 'PO-TRANSFER-SEED',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->transfer(
            productId: $this->product->id,
            fromLocationId: $this->warehouse->id,
            toLocationId: $toLocation->id,
            quantity: '3.00',
            reference: 'TRF-001',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        // Variant-scoped from-location decreased.
        $fromLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($fromLevel);
        $this->assertEquals('7.00', $fromLevel->quantity);

        // Variant-scoped to-location increased.
        $toLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $toLocation->id)
            ->first();
        $this->assertNotNull($toLevel);
        $this->assertEquals('3.00', $toLevel->quantity);

        // No product-level (variant_id NULL) row was created.
        $productLevelCount = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->count();
        $this->assertSame(0, $productLevelCount);
    }

    public function test_reserve_variant_scopes_to_variant_row(): void
    {
        $variant = $this->makeVariant();

        // Seed variant-scoped stock.
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '8.00',
            reference: 'PO-RESERVE-SEED',
            userId: $this->userId,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        $this->service->reserve(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '2.00',
            reference: 'ORD-001',
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );

        // The variant-scoped row's reserved amount increased.
        $variantLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($variantLevel);
        $this->assertEquals('2.00', $variantLevel->reserved);

        // No product-level (variant_id NULL) row was touched.
        $productLevel = StockLevel::query()
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNull($productLevel);
    }
}
