<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockTransferVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private Product $product;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private StockTransferService $service;

    private StockAdjustmentService $stockService;

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
            'name' => 'Acme',
            'legal_name' => 'Acme LLC',
            'tax_id' => 'TAX-ACME',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'U',
            'email' => 'u@e.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'WH',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-01',
            'name' => 'Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TSHIRT',
            'name' => 'T-Shirt',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);

        $this->variantA = $this->makeVariant('RED-L');
        $this->variantB = $this->makeVariant('BLU-M');

        $this->service = app(StockTransferService::class);
        $this->stockService = app(StockAdjustmentService::class);
    }

    private function makeVariant(string $code): ProductVariant
    {
        return $this->makeVariantFor($this->product, $code);
    }

    private function makeVariantFor(Product $product, string $code): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => $code,
            'sku' => $product->sku.'-'.$code,
            'barcode' => null,
            'name_suffix' => $code,
            'is_default' => false,
            'is_active' => true,
            'display_order' => 0,
            'price_override' => null,
            'cost_override' => null,
            'image_url' => null,
        ]);
    }

    /** Seed variant-scoped on-hand stock at a location via the variant-aware receive(). */
    private function seedVariantStock(ProductVariant $variant, Location $loc, string $qty): void
    {
        $this->stockService->receive(
            productId: $variant->product_id,
            locationId: $loc->id,
            quantity: $qty,
            reference: 'SEED',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            variantId: $variant->id,
        );
    }

    private function variantStockQty(ProductVariant $variant, Location $loc): string
    {
        $level = StockLevel::query()
            ->where('product_id', $variant->product_id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $loc->id)
            ->first();

        return $level === null ? '0.0000' : (string) $level->quantity;
    }

    public function test_initiate_moves_only_the_targeted_variant_stock(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '10');
        $this->seedVariantStock($this->variantB, $this->warehouse, '7');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(
                    productId: $this->product->id,
                    quantity: '4',
                    variantId: $this->variantA->id,
                ),
            ],
        ));

        $this->assertSame(TransferStatus::InTransit, $transfer->status);
        // Variant A decremented at source; variant B untouched.
        $this->assertSame('6.0000', $this->variantStockQty($this->variantA, $this->warehouse));
        $this->assertSame('7.0000', $this->variantStockQty($this->variantB, $this->warehouse));
        // The line persisted the variant_id.
        $this->assertSame($this->variantA->id, $transfer->lines->first()->variant_id);
        // The out-movement carries the variant_id.
        $movement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->variantA->id)
            ->where('location_id', $this->warehouse->id)
            ->where('movement_type', 'transfer_out')
            ->first();
        $this->assertNotNull($movement);
    }

    public function test_initiate_without_variant_on_variant_product_throws_invalid_argument(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '5');

        $this->expectException(InvalidArgumentException::class);

        $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '1'),
            ],
        ));
    }

    public function test_initiate_with_foreign_variant_throws_invalid_argument(): void
    {
        $otherProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OTHER',
            'name' => 'Other',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.0000',
            'sale_price' => '2.0000',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                // variantA belongs to $this->product, not $otherProduct.
                new InitiateTransferLineData(
                    productId: $otherProduct->id, quantity: '1', variantId: $this->variantA->id,
                ),
            ],
        ));
    }

    public function test_same_product_two_variants_on_one_transfer_is_allowed(): void
    {
        $this->seedVariantStock($this->variantA, $this->warehouse, '5');
        $this->seedVariantStock($this->variantB, $this->warehouse, '5');

        $transfer = $this->service->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: [
                new InitiateTransferLineData(productId: $this->product->id, quantity: '2', variantId: $this->variantA->id),
                new InitiateTransferLineData(productId: $this->product->id, quantity: '3', variantId: $this->variantB->id),
            ],
        ));

        $this->assertCount(2, $transfer->lines);
        $this->assertSame('3.0000', $this->variantStockQty($this->variantA, $this->warehouse));
        $this->assertSame('2.0000', $this->variantStockQty($this->variantB, $this->warehouse));
    }
}
