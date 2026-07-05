<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
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
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
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
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class GoodsReceiptPriceOverrideTest extends TestCase
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
            'name' => 'GR Price Tenant',
            'slug' => 'gr-price-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR Price Company',
            'legal_name' => 'GR Price Company SARL',
            'tax_id' => 'GR-PRICE-TAX',
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
            'name' => 'GR Price Receiver',
            'email' => 'gr-price-receiver@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('goods-receipt.edit-price');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRP-WH',
            'name' => 'GR Price Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GR Price Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function received_price_override_sets_receipt_basis_wac_and_audit_columns(): void
    {
        $product = $this->product('GRP-OVERRIDE', 'Override Product');
        $po = $this->purchaseOrder($product, paidQty: '100.0000', freeQty: '0.0000', unitPrice: '5.000', landedUnitCost: '5.000000');
        $line = $po->lines->first();

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '100.0000'],
            [],
            [],
            [$line->id => '5.200'],
            'Delivery note unit price',
            $this->user->id,
        );

        $receiptLine = GoodsReceiptLine::query()->where('po_line_id', $line->id)->sole();
        $product->refresh();
        $line->refresh();

        $this->assertSame('5.200', (string) $receiptLine->received_unit_price);
        $this->assertSame('5.200000', (string) $receiptLine->landed_unit_cost);
        $this->assertSame('5.200000', (string) $receiptLine->accrual_unit_cost);
        $this->assertSame('5.200000', (string) $receiptLine->effective_unit_cost);
        $this->assertSame($this->user->id, $receiptLine->price_override_by);
        $this->assertNotNull($receiptLine->price_override_at);
        $this->assertSame('5.000000', (string) $receiptLine->price_override_old_basis);
        $this->assertSame('Delivery note unit price', $receiptLine->price_override_reason);
        $this->assertSame('5.200000', (string) $line->accrual_unit_cost);
        $this->assertSame('5.200000', (string) $product->cost_price);
        $this->assertSame('5.200000', (string) $product->last_purchase_cost);

        $movement = StockMovement::query()->where('product_id', $product->id)->sole();
        $this->assertSame('5.200000', (string) $movement->unit_cost);
    }

    #[Test]
    public function no_override_keeps_received_price_null_and_uses_po_basis(): void
    {
        $product = $this->product('GRP-PO-BASIS', 'PO Basis Product');
        $po = $this->purchaseOrder($product, paidQty: '10.0000', freeQty: '0.0000', unitPrice: '5.000', landedUnitCost: '5.100000');
        $line = $po->lines->first();

        app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '10.0000']);

        $receiptLine = GoodsReceiptLine::query()->where('po_line_id', $line->id)->sole();

        $this->assertNull($receiptLine->received_unit_price);
        $this->assertSame('5.100000', (string) $receiptLine->landed_unit_cost);
        $this->assertSame('5.100000', (string) $receiptLine->accrual_unit_cost);
        $this->assertNull($receiptLine->price_override_by);
        $this->assertNull($receiptLine->price_override_at);
        $this->assertNull($receiptLine->price_override_old_basis);
        $this->assertNull($receiptLine->price_override_reason);
    }

    #[Test]
    public function paid_and_free_receipt_writes_paid_movement_last_and_effective_unit_cost_is_blended(): void
    {
        $product = $this->product('GRP-BONUS', 'Override Bonus Product');
        $po = $this->purchaseOrder($product, paidQty: '100.0000', freeQty: '10.0000', unitPrice: '5.000', landedUnitCost: '5.000000');
        $line = $po->lines->first();

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '100.0000'],
            [],
            [$line->id => '10.0000'],
            [$line->id => '5.200'],
            'Bonus delivery note',
            $this->user->id,
        );

        $receiptLine = GoodsReceiptLine::query()->where('po_line_id', $line->id)->sole();
        $product->refresh();

        $this->assertSame('4.727273', (string) $receiptLine->effective_unit_cost);
        $this->assertSame('4.727272', (string) $product->cost_price);
        $this->assertSame('5.200000', (string) $product->last_purchase_cost);

        $movements = StockMovement::query()
            ->where('product_id', $product->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame('10.0000', (string) $movements[0]->quantity);
        $this->assertSame('0.000000', (string) $movements[0]->unit_cost);
        $this->assertSame('100.0000', (string) $movements[1]->quantity);
        $this->assertSame('5.200000', (string) $movements[1]->unit_cost);
    }

    #[Test]
    public function total_price_entry_mode_still_accepts_per_unit_received_price_override(): void
    {
        $product = $this->product('GRP-TOTAL-MODE', 'Total Mode Product');
        $po = $this->purchaseOrder(
            $product,
            paidQty: '10.0000',
            freeQty: '0.0000',
            unitPrice: '5.000',
            landedUnitCost: '5.000000',
            priceEntryMode: PriceEntryMode::Total,
        );
        $line = $po->lines->first();

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '10.0000'],
            [],
            [],
            [$line->id => '5.200'],
            'Total-mode PO delivery note',
            $this->user->id,
        );

        $receiptLine = GoodsReceiptLine::query()->where('po_line_id', $line->id)->sole();

        $this->assertSame('5.200', (string) $receiptLine->received_unit_price);
        $this->assertSame('5.200000', (string) $receiptLine->accrual_unit_cost);
    }

    #[Test]
    public function received_price_override_rounds_by_purchase_order_currency_scale(): void
    {
        $product = $this->product('GRP-USD-SCALE', 'USD Scale Override Product');
        $po = $this->purchaseOrder(
            $product,
            paidQty: '10.0000',
            freeQty: '0.0000',
            unitPrice: '5.000',
            landedUnitCost: '5.000000',
            currency: 'USD',
        );
        $line = $po->lines->first();

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '10.0000'],
            [],
            [],
            [$line->id => '5.205'],
            'USD delivery note price',
            $this->user->id,
        );

        $receiptLine = GoodsReceiptLine::query()->where('po_line_id', $line->id)->sole();

        $this->assertSame('5.210', (string) $receiptLine->received_unit_price);
        $this->assertSame('5.210000', (string) $receiptLine->landed_unit_cost);
    }

    #[Test]
    public function service_rejects_non_positive_received_unit_price_overrides(): void
    {
        $product = $this->product('GRP-NON-POSITIVE', 'Non Positive Override Product');
        $po = $this->purchaseOrder($product, paidQty: '10.0000', freeQty: '0.0000', unitPrice: '5.000', landedUnitCost: '5.000000');
        $line = $po->lines->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('received_unit_price must be greater than zero');

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '10.0000'],
            [],
            [],
            [$line->id => '0.000'],
            'Invalid delivery note price',
            $this->user->id,
        );
    }

    private function product(string $sku, string $name): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    private function purchaseOrder(
        Product $product,
        string $paidQty,
        string $freeQty,
        string $unitPrice,
        string $landedUnitCost,
        PriceEntryMode $priceEntryMode = PriceEntryMode::Unit,
        string $currency = 'TND',
    ): Document {
        $lineTotal = bcmul($paidQty, $unitPrice, 3);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GRP-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => $currency,
            'subtotal' => $lineTotal,
            'tax_amount' => '0.000',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $paidQty,
            'free_quantity' => $freeQty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => $landedUnitCost,
            'price_entry_mode' => $priceEntryMode->value,
        ]);

        return $po->fresh(['lines']);
    }
}
