<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Jobs\DailyExpiryCheck;
use App\Modules\BatchExpiry\Notifications\CriticalBatchExpiryNotification;
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
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BatchChainE2ETest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private Partner $supplier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-24 08:00:00');

        $this->tenant = Tenant::create([
            'name' => 'Batch Chain Tenant',
            'slug' => 'batch-chain-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Batch Chain Company',
            'legal_name' => 'Batch Chain Company LLC',
            'tax_id' => 'TAX-BATCH-001',
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
            'name' => 'Batch Chain User',
            'email' => 'batch-chain@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'batches.view',
            'inventory.receive',
            'inventory.transfer',
            'inventory.adjust',
            'pos.operate_terminal',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-BATCH',
            'name' => 'Batch Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SHOP-BATCH',
            'name' => 'Batch Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Batch Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->shop->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);
    }

    public function test_purchase_receipt_transfer_pos_fefo_sale_and_expiry_check_chain(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'BATCH-CHAIN-001',
            'name' => 'Batch Chain Medicine',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => '4.00',
            'sale_price' => '8.00',
            'tax_rate' => '0.00',
        ]);

        $this->assertTrue($product->requires_batch_tracking);

        $po = $this->createConfirmedPurchaseOrder($product, '10.0000');
        $line = $po->lines->firstOrFail();

        app(GoodsReceiptService::class)->receiveGoods(
            $po,
            [$line->id => '10.0000'],
            [
                $line->id => [
                    'batch_number' => 'FEFO-CHAIN-001',
                    'expiry_date' => now()->addDay()->toDateString(),
                    'manufacturing_date' => now()->subDays(10)->toDateString(),
                ],
            ],
        );

        $batch = Batch::where('product_id', $product->id)
            ->where('batch_number', 'FEFO-CHAIN-001')
            ->firstOrFail();

        $this->assertStockLevel($product->id, $this->warehouse->id, '10.0000');
        $this->assertBatchStock($batch->id, $this->warehouse->id, '10.0000');

        $stockAdjustmentService = app(StockAdjustmentService::class);
        $stockAdjustmentService->issue(
            productId: $product->id,
            locationId: $this->warehouse->id,
            quantity: '10.0000',
            reference: 'Batch chain transfer out',
            userId: $this->user->id,
            batchId: (int) $batch->id,
            expectedCompanyId: $this->company->id,
        );
        $stockAdjustmentService->receive(
            productId: $product->id,
            locationId: $this->shop->id,
            quantity: '10.0000',
            reference: 'Batch chain transfer in',
            userId: $this->user->id,
            batchId: (int) $batch->id,
            expectedCompanyId: $this->company->id,
        );

        $this->assertStockLevel($product->id, $this->warehouse->id, '0.0000');
        $this->assertStockLevel($product->id, $this->shop->id, '10.0000');
        $this->assertBatchStock($batch->id, $this->warehouse->id, '0.0000');
        $this->assertBatchStock($batch->id, $this->shop->id, '10.0000');

        app(ReceiptCreationService::class)->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $product->id,
                    'quantity' => '4.0000',
                    'unit_price' => '8.00',
                ],
            ],
        );

        $this->assertStockLevel($product->id, $this->shop->id, '6.0000');
        $this->assertBatchStock($batch->id, $this->shop->id, '6.0000');

        $allocation = ReceiptLineBatchAllocation::where('batch_id', $batch->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals(0, bccomp('4.000', (string) $allocation->quantity, 3));
        $this->assertSame('FEFO-CHAIN-001', $allocation->batch_number);

        Notification::fake();
        app(PermissionRegistrar::class)->setPermissionsTeamId('original-team-id');

        app(DailyExpiryCheck::class)->handle();

        Notification::assertSentTo($this->user, CriticalBatchExpiryNotification::class);
        $this->assertFalse($batch->refresh()->is_expired);
        $this->assertSame('original-team-id', app(PermissionRegistrar::class)->getPermissionsTeamId());

        $this->travelTo('2026-05-26 08:00:00');

        app(DailyExpiryCheck::class)->handle();

        $this->assertTrue($batch->refresh()->is_expired);
    }

    private function createConfirmedPurchaseOrder(Product $product, string $quantity): Document
    {
        $lineTotal = bcmul($quantity, '4.0000', 4);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-BATCH-CHAIN',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $lineTotal,
            'tax_amount' => '0.00',
            'total' => $lineTotal,
        ]);

        DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $quantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'unit_price' => '4.0000',
            'line_total' => $lineTotal,
            'allocated_costs' => '0.0000',
        ]);

        return $po->fresh(['lines']);
    }

    private function assertStockLevel(string $productId, string $locationId, string $expectedQuantity): void
    {
        $stock = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(0, bccomp($expectedQuantity, (string) $stock->quantity, 4));
    }

    private function assertBatchStock(int $batchId, string $locationId, string $expectedQuantity): void
    {
        $batchStock = BatchStock::where('batch_id', $batchId)
            ->where('location_id', $locationId)
            ->first();

        $this->assertNotNull($batchStock);
        $this->assertEquals(0, bccomp($expectedQuantity, (string) $batchStock->quantity, 4));
    }
}
