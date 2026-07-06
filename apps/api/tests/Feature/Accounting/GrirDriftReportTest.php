<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GrirDriftReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GRIR Drift Tenant',
            'slug' => 'grir-drift-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GRIR Drift Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GRIR-DRIFT-WH',
            'name' => 'GRIR Drift Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'GRIR Drift Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function command_reports_missing_paid_movement_grir_and_ignores_zero_cost_free_movements(): void
    {
        $paidAndFreeProduct = $this->createProduct('GRIR-PAID-FREE', 'Paid and Free Product');
        $paidAndFreePo = $this->createConfirmedPurchaseOrder(
            product: $paidAndFreeProduct,
            documentNumber: 'PO-GRIR-PAID-FREE',
            quantity: '5.0000',
            freeQuantity: '1.0000',
        );
        $paidAndFreeLine = $paidAndFreePo->lines->sole();

        $paidAndFreeReceipt = app(GoodsReceiptService::class)->receiveGoods(
            $paidAndFreePo,
            [$paidAndFreeLine->id => '5.0000'],
            [],
            [$paidAndFreeLine->id => '1.0000'],
        )->receipt;

        $freeOnlyProduct = $this->createProduct('GRIR-FREE-ONLY', 'Free Only Product');
        $freeOnlyPo = $this->createConfirmedPurchaseOrder(
            product: $freeOnlyProduct,
            documentNumber: 'PO-GRIR-FREE-ONLY',
            quantity: '0.0000',
            freeQuantity: '2.0000',
        );
        $freeOnlyLine = $freeOnlyPo->lines->sole();

        app(GoodsReceiptService::class)->receiveGoods(
            $freeOnlyPo,
            [],
            [],
            [$freeOnlyLine->id => '2.0000'],
        );

        /** @var GoodsReceiptLine $receiptLine */
        $receiptLine = GoodsReceiptLine::query()
            ->where('goods_receipt_id', $paidAndFreeReceipt->id)
            ->sole();

        $this->assertNotNull($receiptLine->movement_id);
        $this->assertNotNull($receiptLine->free_movement_id);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'goods_receipt',
            'source_id' => $receiptLine->movement_id,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'goods_receipt',
            'source_id' => $receiptLine->free_movement_id,
        ]);

        $this->artisan('procurement:grir-drift')
            ->expectsOutput('GR-IR drift: none')
            ->assertExitCode(0);

        $entryId = DB::table('journal_entries')
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receiptLine->movement_id)
            ->value('id');
        $this->assertIsString($entryId);

        DB::table('journal_lines')->where('journal_entry_id', $entryId)->delete();
        DB::table('journal_entries')->where('id', $entryId)->delete();

        $this->artisan('procurement:grir-drift')
            ->expectsOutputToContain($paidAndFreeReceipt->receipt_number)
            ->assertExitCode(1);
    }

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
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    #[Test]
    public function command_does_not_flag_zero_cost_paid_movements(): void
    {
        // Mirror of GeneralLedgerService::createGoodsReceiptGrIrEntry's early return:
        // a PAID movement whose amount rounds to zero legitimately has NO GR-IR entry.
        $zeroCostProduct = $this->createProduct('GRIR-ZERO-PAID', 'Zero Cost Paid Product');
        $zeroCostPo = $this->createConfirmedPurchaseOrder(
            product: $zeroCostProduct,
            documentNumber: 'PO-GRIR-ZERO-PAID',
            quantity: '3.0000',
            freeQuantity: '0.0000',
            unitPrice: '0.000',
        );
        $zeroCostLine = $zeroCostPo->lines->sole();

        $receipt = app(GoodsReceiptService::class)->receiveGoods(
            $zeroCostPo,
            [$zeroCostLine->id => '3.0000'],
        )->receipt;

        /** @var GoodsReceiptLine $receiptLine */
        $receiptLine = GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->sole();

        $this->assertNotNull($receiptLine->movement_id);
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'goods_receipt',
            'source_id' => $receiptLine->movement_id,
        ]);

        $this->artisan('procurement:grir-drift')
            ->expectsOutput('GR-IR drift: none')
            ->assertExitCode(0);
    }

    private function createConfirmedPurchaseOrder(
        Product $product,
        string $documentNumber,
        string $quantity,
        string $freeQuantity,
        string $unitPrice = '10.000',
    ): Document {
        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);

        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => $quantity,
            'free_quantity' => $freeQuantity,
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => bcmul($quantity, $unitPrice, 3),
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => bcadd($unitPrice, '0', 6),
            'price_entry_mode' => 'unit',
        ]);

        /** @var Document $fresh */
        $fresh = $purchaseOrder->fresh(['lines']);

        return $fresh;
    }
}
