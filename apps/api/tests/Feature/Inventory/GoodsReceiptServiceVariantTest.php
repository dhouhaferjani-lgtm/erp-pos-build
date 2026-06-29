<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 20: GoodsReceiptService variant pass-through tests.
 *
 * Verifies that variant_id on a purchase-order line is threaded through
 * WeightedAverageCostService into the correct variant-scoped StockLevel row,
 * and that non-variant lines continue to land on product-level stock (null
 * variant_id).
 */
class GoodsReceiptServiceVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GRV Test Tenant',
            'slug' => 'grv-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GRV Test Company',
            'legal_name' => 'GRV Test Company LLC',
            'tax_id' => 'TAX-GRV-001',
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
            'name' => 'GRV Test User',
            'email' => 'grv-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GRV-01',
            'name' => 'GRV Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GRV Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createProduct(string $sku, string $name): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '0.00',
        ]);
    }

    private function createVariant(Product $product, string $suffix): ProductVariant
    {
        return ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-'.$suffix,
            'sku' => $product->sku.'-'.$suffix,
            'name_suffix' => $suffix,
            'is_active' => true,
            'is_default' => false,
            'display_order' => 0,
        ]);
    }

    /**
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, variant_id?: string|null}>  $lineItems
     */
    private function createConfirmedPO(array $lineItems): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GRV-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        $lineNumber = 1;
        $subtotal = '0.00';

        foreach ($lineItems as $item) {
            $lineTotal = bcmul($item['quantity'], $item['unit_price'], 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);

            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $item['product']->id,
                'variant_id' => $item['variant_id'] ?? null,
                'product_code' => $item['product']->sku,
                'line_number' => $lineNumber++,
                'description' => $item['product']->name,
                'quantity' => $item['quantity'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'unit_price' => $item['unit_price'],
                'line_total' => $lineTotal,
                'allocated_costs' => '0.0000',
            ]);
        }

        $po->update([
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);

        return $po->fresh(['lines']);
    }

    // =========================================================================
    // Tests
    // =========================================================================

    /**
     * A PO line with variant_id set must create a variant-scoped StockLevel row
     * (product_id + variant_id + location_id) and must NOT touch the
     * product-level row (variant_id IS NULL).
     */
    public function test_finalize_receipt_with_variant_line_creates_variant_stock(): void
    {
        $product = $this->createProduct('PROD-VAR-GR', 'Variant GR Product');
        $variant = $this->createVariant($product, 'RED');

        $po = $this->createConfirmedPO([
            [
                'product' => $product,
                'quantity' => '10.0000',
                'unit_price' => '5.000',
                'variant_id' => $variant->id,
            ],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = (string) $line->quantity;
        }

        $result = $service->receiveGoods($po, $receivedQty);

        $this->assertEquals(DocumentStatus::Received, $result->status);

        // Variant-scoped stock row must exist with the correct quantity
        $variantStock = StockLevel::where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($variantStock, 'A variant-scoped StockLevel must be created on receipt');
        $this->assertEquals(
            0,
            bccomp('10.0000', (string) $variantStock->quantity, 4),
            "Variant stock quantity should be 10.0000, got {$variantStock->quantity}"
        );

        // Product-level stock row (variant_id IS NULL) must NOT exist
        $productLevelStock = StockLevel::where('product_id', $product->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNull(
            $productLevelStock,
            'No product-level (variant_id IS NULL) StockLevel should be created for a variant line'
        );

        // Stock movement must carry variant_id
        $movement = StockMovement::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($movement, 'A StockMovement must be recorded');
        $this->assertEquals(
            $variant->id,
            $movement->variant_id,
            'StockMovement must carry the variant_id from the PO line'
        );
    }

    /**
     * A PO line without variant_id (non-variant product) must create a
     * product-level StockLevel (variant_id IS NULL) — backward compat.
     */
    public function test_finalize_non_variant_line_creates_product_stock(): void
    {
        $product = $this->createProduct('PROD-NOVAR-GR', 'Non-Variant GR Product');

        $po = $this->createConfirmedPO([
            [
                'product' => $product,
                'quantity' => '8.0000',
                'unit_price' => '12.000',
                // No variant_id key — defaults to null
            ],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = (string) $line->quantity;
        }

        $result = $service->receiveGoods($po, $receivedQty);

        $this->assertEquals(DocumentStatus::Received, $result->status);

        // Product-level stock row must exist
        $productLevelStock = StockLevel::where('product_id', $product->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($productLevelStock, 'A product-level StockLevel must be created for a non-variant line');
        $this->assertEquals(
            0,
            bccomp('8.0000', (string) $productLevelStock->quantity, 4),
            "Product stock quantity should be 8.0000, got {$productLevelStock->quantity}"
        );

        // No variant-scoped rows should exist
        $variantStock = StockLevel::where('product_id', $product->id)
            ->whereNotNull('variant_id')
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNull($variantStock, 'No variant-scoped StockLevel should be created for a non-variant line');

        // Stock movement must have null variant_id
        $movement = StockMovement::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertNull($movement->variant_id, 'StockMovement variant_id must be null for a non-variant line');
    }

    /**
     * Two lines on the same PO — one variant, one non-variant (different
     * products) — must each land on their respective stock buckets without
     * cross-contamination.
     */
    public function test_mixed_variant_and_non_variant_lines_land_on_correct_buckets(): void
    {
        $productA = $this->createProduct('PROD-VAR-MIX', 'Variant Mix Product');
        $variant = $this->createVariant($productA, 'BLUE');

        $productB = $this->createProduct('PROD-NOVAR-MIX', 'Non-Variant Mix Product');

        $po = $this->createConfirmedPO([
            [
                'product' => $productA,
                'quantity' => '5.0000',
                'unit_price' => '10.000',
                'variant_id' => $variant->id,
            ],
            [
                'product' => $productB,
                'quantity' => '15.0000',
                'unit_price' => '3.000',
                // No variant_id — product-level
            ],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = (string) $line->quantity;
        }

        $result = $service->receiveGoods($po, $receivedQty);

        $this->assertEquals(DocumentStatus::Received, $result->status);

        // Product A: variant-scoped row
        $variantStock = StockLevel::where('product_id', $productA->id)
            ->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($variantStock);
        $this->assertEquals(0, bccomp('5.0000', (string) $variantStock->quantity, 4));

        // Product A: no product-level row
        $this->assertNull(
            StockLevel::where('product_id', $productA->id)->whereNull('variant_id')->first()
        );

        // Product B: product-level row
        $productBStock = StockLevel::where('product_id', $productB->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($productBStock);
        $this->assertEquals(0, bccomp('15.0000', (string) $productBStock->quantity, 4));
    }

    /**
     * REGRESSION (§6.7 Option C / product-grain WAC): recordPurchase must compute
     * the weighted-average cost over the PRODUCT-GRAIN inventory total (sum across
     * ALL variant rows at the same location), NOT the single variant row being
     * received into. Task 20 scoped the WAC currentQty to the variant row, which
     * sharded the average per-variant.
     *
     * Worked example: product cost 10.00, 5 RED + 7 BLUE on hand (12 units),
     * receive 3 RED @ 14.00.
     *   Correct product-grain WAC = (12*10 + 3*14) / 15 = 162 / 15 = 10.80
     *   Buggy variant-sharded WAC = (5*10  + 3*14) / 8  =  92 / 8  = 11.50
     *
     * The variant-A (RED) physical row must still increase to 8 (row update
     * stays variant-scoped).
     */
    public function test_record_purchase_keeps_wac_product_grain_across_variants(): void
    {
        $product = $this->createProduct('PROD-WAC-GRAIN', 'WAC Grain Product');
        $product->cost_price = '10.0000';
        $product->save();

        $variantRed = $this->createVariant($product, 'RED');
        $variantBlue = $this->createVariant($product, 'BLUE');

        // Existing on-hand: 5 RED + 7 BLUE at the warehouse.
        StockLevel::create([
            'product_id' => $product->id,
            'variant_id' => $variantRed->id,
            'location_id' => $this->warehouse->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'quantity' => '5.0000',
            'reserved' => '0.0000',
        ]);
        StockLevel::create([
            'product_id' => $product->id,
            'variant_id' => $variantBlue->id,
            'location_id' => $this->warehouse->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'quantity' => '7.0000',
            'reserved' => '0.0000',
        ]);

        $service = app(WeightedAverageCostService::class);

        // Receive 3 RED @ 14.00.
        $service->recordPurchase(
            product: $product,
            location: $this->warehouse,
            quantity: '3',
            landedUnitCost: '14',
            reference: 'PO-WAC-GRAIN',
            referenceType: null,
            referenceId: null,
            variantId: $variantRed->id,
        );

        // Product cost_price must be the product-grain WAC (10.80), NOT 11.50.
        $product->refresh();
        $this->assertEquals(
            0,
            bccomp('10.8000', (string) $product->cost_price, 4),
            "Product cost_price must be product-grain WAC 10.80, got {$product->cost_price}"
        );

        // Variant-A (RED) row must still be variant-scoped: 5 + 3 = 8.
        $redStock = StockLevel::where('product_id', $product->id)
            ->where('variant_id', $variantRed->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($redStock);
        $this->assertEquals(
            0,
            bccomp('8.0000', (string) $redStock->quantity, 4),
            "RED variant stock row must increase to 8, got {$redStock->quantity}"
        );

        // Variant-B (BLUE) row must be untouched at 7.
        $blueStock = StockLevel::where('product_id', $product->id)
            ->where('variant_id', $variantBlue->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($blueStock);
        $this->assertEquals(
            0,
            bccomp('7.0000', (string) $blueStock->quantity, 4),
            "BLUE variant stock row must stay at 7, got {$blueStock->quantity}"
        );
    }
}
