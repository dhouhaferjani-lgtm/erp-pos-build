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
use App\Modules\Document\Domain\Services\Conversion\Converters\PurchaseOrderToGoodsReceiptConverter;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptResult;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
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

final class GoodsReceiptLedgerWriteTest extends TestCase
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
            'name' => 'GR Ledger Tenant',
            'slug' => 'gr-ledger-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR Ledger Company',
            'legal_name' => 'GR Ledger Company SARL',
            'tax_id' => 'GR-LEDGER-TAX',
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
            'name' => 'GR Ledger Receiver',
            'email' => 'gr-ledger-receiver@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
            'purchase-orders.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRL-WH',
            'name' => 'GR Ledger Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GR Ledger Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function receive_goods_writes_one_posted_header_and_lines_with_movement_links(): void
    {
        $productA = $this->createProduct('GRL-A', 'Ledger Product A');
        $productB = $this->createProduct('GRL-B', 'Ledger Product B');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $productA, 'quantity' => '10.0000', 'free_quantity' => '0.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.500000'],
            ['product' => $productB, 'quantity' => '4.0000', 'free_quantity' => '0.0000', 'unit_price' => '8.000', 'landed_unit_cost' => '8.000000'],
        ]);

        $result = app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [
                $po->lines[0]->id => '6.0000',
                $po->lines[1]->id => '4.0000',
            ],
            [],
            [],
            $this->user->id,
        );

        $this->assertInstanceOf(GoodsReceiptResult::class, $result);
        $this->assertSame($result->purchaseOrder->id, $po->id);
        $this->assertSame(GoodsReceiptStatus::Posted, $result->receipt->status);
        $this->assertSame($this->user->id, $result->receipt->received_by);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0001$/', $result->receipt->receipt_number);

        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->orderBy('po_line_id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('0.0000', (string) $lines[0]->free_qty);
        $this->assertSame('5.500000', (string) $lines[0]->landed_unit_cost);
        $this->assertSame('5.500000', (string) $lines[0]->accrual_unit_cost);
        $this->assertSame('5.500000', (string) $lines[0]->effective_unit_cost);
        $this->assertNotNull($lines[0]->movement_id);
        $this->assertNull($lines[0]->free_movement_id);
        $this->assertSame('0.0000', (string) $lines[0]->quantity_invoiced);
        $this->assertNull($lines[0]->received_unit_price);

        $this->assertLedgerCountersMatchPo($result->purchaseOrder->fresh(['lines']) ?? $result->purchaseOrder);
    }

    #[Test]
    public function partial_receipts_create_one_header_per_receive_call(): void
    {
        $product = $this->createProduct('GRL-PARTIAL', 'Ledger Partial Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '0.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();

        $first = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '4.0000'], [], [], $this->user->id);
        $second = app(GoodsReceiptService::class)->receiveGoods($first->purchaseOrder, [$line->id => '6.0000'], [], [], $this->user->id);

        $this->assertCount(2, GoodsReceipt::query()->where('purchase_order_id', $po->id)->orderBy('receipt_number')->get());
        $this->assertNotSame($first->receipt->id, $second->receipt->id);
        $this->assertMatchesRegularExpression('/^GRN-\d{4}-0002$/', $second->receipt->receipt_number);
        $this->assertLedgerCountersMatchPo($second->purchaseOrder->fresh(['lines']) ?? $second->purchaseOrder);
    }

    #[Test]
    public function free_only_receipt_writes_a_line_with_only_the_free_movement_link(): void
    {
        $product = $this->createProduct('GRL-FREE', 'Ledger Free Product', lastPurchaseCost: '5.000000');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '10.0000', 'free_quantity' => '2.0000', 'unit_price' => '5.000', 'landed_unit_cost' => '5.000000'],
        ]);
        $line = $po->lines->first();

        $result = app(GoodsReceiptService::class)->receiveGoods($po, [], [], [$line->id => '2.0000'], $this->user->id);

        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->sole();
        $this->assertSame('0.0000', (string) $receiptLine->received_qty);
        $this->assertSame('2.0000', (string) $receiptLine->free_qty);
        $this->assertNull($receiptLine->movement_id);
        $this->assertNotNull($receiptLine->free_movement_id);
        $this->assertSame('0.000000', (string) $receiptLine->effective_unit_cost);
        $this->assertLedgerCountersMatchPo($result->purchaseOrder->fresh(['lines']) ?? $result->purchaseOrder);
    }

    #[Test]
    public function paid_only_effective_unit_cost_uses_the_same_half_up_rounding_as_landed_cost(): void
    {
        $product = $this->createProduct('GRL-ROUND', 'Ledger Rounding Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '1.0000', 'free_quantity' => '0.0000', 'unit_price' => '1.000', 'landed_unit_cost' => '1.1234567'],
        ]);
        $line = $po->lines->first();

        $result = app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '1.0000'], [], [], $this->user->id);

        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $result->receipt->id)->sole();
        $this->assertSame('1.123457', (string) $receiptLine->landed_unit_cost);
        $this->assertSame('1.123457', (string) $receiptLine->effective_unit_cost);
    }

    #[Test]
    public function receive_all_writes_a_receipt_header(): void
    {
        $product = $this->createProduct('GRL-ALL', 'Ledger Receive All Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);

        $result = app(GoodsReceiptService::class)->receiveAll($po, $this->user->id);

        $this->assertInstanceOf(GoodsReceiptResult::class, $result);
        $this->assertSame($po->id, $result->receipt->purchase_order_id);
    }

    #[Test]
    public function purchase_order_converter_stamps_the_actor_on_the_goods_receipt(): void
    {
        $product = $this->createProduct('GRL-CONVERT', 'Ledger Converter Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '2.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        app(PurchaseOrderToGoodsReceiptConverter::class)->convert($po, [
            'received_quantities' => [$line->id => '2.0000'],
            'actor_user_id' => $this->user->id,
        ]);

        $this->assertSame($this->user->id, GoodsReceipt::query()->sole()->received_by);
    }

    #[Test]
    public function domain_exception_rolls_back_the_receipt_header(): void
    {
        $product = $this->createProduct('GRL-ROLLBACK', 'Ledger Rollback Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        try {
            app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '4.0000'], [], [], $this->user->id);
            $this->fail('Expected over-receipt to throw.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('goods_receipts', 0);
            $this->assertDatabaseCount('goods_receipt_lines', 0);
        }
    }

    #[Test]
    public function receive_endpoint_returns_goods_receipt_meta(): void
    {
        $product = $this->createProduct('GRL-META', 'Ledger Meta Product');
        $po = $this->createConfirmedPurchaseOrder([
            ['product' => $product, 'quantity' => '3.0000', 'free_quantity' => '0.0000', 'unit_price' => '9.000', 'landed_unit_cost' => '9.000000'],
        ]);
        $line = $po->lines->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [$line->id => '3.0000'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('meta.goods_receipt.receipt_number', fn (string $number): bool => str_starts_with($number, 'GRN-'));
        $response->assertJsonPath('meta.goods_receipt.id', GoodsReceipt::query()->sole()->id);
    }

    /**
     * @param  list<array{product: Product, quantity: string, free_quantity: string, unit_price: string, landed_unit_cost: string}>  $lines
     */
    private function createConfirmedPurchaseOrder(array $lines): Document
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
            'document_number' => 'PO-GRL-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        $lineNumber = 1;
        foreach ($lines as $line) {
            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $line['product']->id,
                'product_code' => $line['product']->sku,
                'line_number' => $lineNumber++,
                'description' => $line['product']->name,
                'quantity' => $line['quantity'],
                'free_quantity' => $line['free_quantity'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'free_quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => $line['unit_price'],
                'line_total' => bcmul($line['quantity'], $line['unit_price'], 3),
                'allocated_costs' => '0.000000',
                'landed_unit_cost' => $line['landed_unit_cost'],
                'price_entry_mode' => 'unit',
            ]);
        }

        return $po->fresh(['lines']);
    }

    private function createProduct(string $sku, string $name, string $lastPurchaseCost = '0.000000'): Product
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
            'last_purchase_cost' => $lastPurchaseCost,
        ]);
    }

    private function assertLedgerCountersMatchPo(Document $purchaseOrder): void
    {
        foreach ($purchaseOrder->lines as $line) {
            $ledgerTotals = GoodsReceiptLine::query()
                ->where('po_line_id', $line->id)
                ->selectRaw('COALESCE(SUM(received_qty), 0) as received_qty')
                ->selectRaw('COALESCE(SUM(free_qty), 0) as free_qty')
                ->first();

            $this->assertSame(0, bccomp((string) $line->quantity_received, (string) $ledgerTotals->received_qty, 4));
            $this->assertSame(0, bccomp((string) $line->free_quantity_received, (string) $ledgerTotals->free_qty, 4));
        }
    }
}
