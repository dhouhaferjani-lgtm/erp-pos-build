<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GoodsReceiptBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GR Backfill Tenant',
            'slug' => 'gr-backfill-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = $this->createCompany('GR Backfill Company', 'GR-BF-TAX');
        $this->otherCompany = $this->createCompany('Other GR Backfill Company', 'GR-BF-TAX-2');

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'GR-BF-WH',
            'name' => 'GR Backfill Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function backfill_synthesizes_headers_lines_fifo_invoiced_quantities_and_free_pairing(): void
    {
        $product = $this->createProduct('GR-BF-PROD');
        $po = $this->createPurchaseOrder();
        $poLine = $this->createPoLine($po, $product, quantity: '10.0000', invoiced: '7.0000', unitCost: '5.000000');
        $firstAt = CarbonImmutable::parse('2026-07-01 10:00:00');
        $secondAt = CarbonImmutable::parse('2026-07-02 11:00:00');

        $firstPaid = $this->createMovement($product, $po, '4.0000', '5.000000', $firstAt);
        $secondPaid = $this->createMovement($product, $po, '6.0000', '5.000000', $secondAt);
        $secondFree = $this->createMovement($product, $po, '1.0000', '0.000000', $secondAt);

        $this->artisan('procurement:backfill-goods-receipts')
            ->expectsOutputToContain('Created receipts: 2')
            ->assertExitCode(0);

        $receipts = GoodsReceipt::query()->where('purchase_order_id', $po->id)->orderBy('received_at')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame($firstAt->toDateTimeString(), $receipts[0]->received_at->toDateTimeString());
        $this->assertNull($receipts[0]->received_by);
        $this->assertIsArray($receipts[0]->payload);
        $this->assertArrayHasKey('backfilled_at', $receipts[0]->payload);

        $lines = GoodsReceiptLine::query()->where('po_line_id', $poLine->id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($firstPaid->id, $lines[0]->movement_id);
        $this->assertNull($lines[0]->free_movement_id);
        $this->assertSame('4.0000', (string) $lines[0]->received_qty);
        $this->assertSame('0.0000', (string) $lines[0]->free_qty);
        $this->assertSame('4.0000', (string) $lines[0]->quantity_invoiced);
        $this->assertSame('5.000000', (string) $lines[0]->accrual_unit_cost);

        $this->assertSame($secondPaid->id, $lines[1]->movement_id);
        $this->assertSame($secondFree->id, $lines[1]->free_movement_id);
        $this->assertSame('6.0000', (string) $lines[1]->received_qty);
        $this->assertSame('1.0000', (string) $lines[1]->free_qty);
        $this->assertSame('3.0000', (string) $lines[1]->quantity_invoiced);
        $this->assertSame('4.285714', (string) $lines[1]->effective_unit_cost);

        $this->artisan('procurement:backfill-goods-receipts')
            ->expectsOutputToContain('Created receipts: 0')
            ->assertExitCode(0);
        $this->assertDatabaseCount('goods_receipts', 2);
        $this->assertDatabaseCount('goods_receipt_lines', 2);
    }

    #[Test]
    public function dry_run_and_company_scope_do_not_write_outside_the_requested_company(): void
    {
        $product = $this->createProduct('GR-BF-SCOPE');
        $po = $this->createPurchaseOrder();
        $this->createPoLine($po, $product, quantity: '2.0000', invoiced: '0.0000', unitCost: '5.000000');
        $this->createMovement($product, $po, '2.0000', '5.000000', CarbonImmutable::parse('2026-07-01 10:00:00'));

        $otherProduct = $this->createProduct('GR-BF-OTHER', $this->otherCompany);
        $otherPo = $this->createPurchaseOrder($this->otherCompany);
        $this->createPoLine($otherPo, $otherProduct, quantity: '2.0000', invoiced: '0.0000', unitCost: '5.000000');
        $this->createMovement($otherProduct, $otherPo, '2.0000', '5.000000', CarbonImmutable::parse('2026-07-01 10:00:00'), $this->otherCompany);

        $this->artisan('procurement:backfill-goods-receipts', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Eligible movements: 1')
            ->assertExitCode(0);
        $this->assertDatabaseCount('goods_receipts', 0);

        $this->artisan('procurement:backfill-goods-receipts', ['--company' => $this->company->id])
            ->expectsOutputToContain('Created receipts: 1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertSame($this->company->id, GoodsReceipt::query()->sole()->company_id);
    }

    #[Test]
    public function duplicate_product_po_lines_warn_and_fall_back_to_fifo_assignment(): void
    {
        $product = $this->createProduct('GR-BF-DUP');
        $po = $this->createPurchaseOrder();
        $firstLine = $this->createPoLine($po, $product, quantity: '1.0000', invoiced: '0.0000', unitCost: '5.000000', lineNumber: 1);
        $this->createPoLine($po, $product, quantity: '1.0000', invoiced: '0.0000', unitCost: '6.000000', lineNumber: 2);
        $this->createMovement($product, $po, '1.0000', '5.000000', CarbonImmutable::parse('2026-07-01 10:00:00'));

        $this->artisan('procurement:backfill-goods-receipts')
            ->expectsOutputToContain('duplicate product PO lines')
            ->assertExitCode(0);

        $receiptLine = GoodsReceiptLine::query()->sole();
        $this->assertSame($firstLine->id, $receiptLine->po_line_id);
    }

    private function createCompany(string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => "{$name} SARL",
            'tax_id' => $taxId,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createProduct(string $sku, ?Company $company = null): Product
    {
        $company ??= $this->company;

        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'sku' => $sku,
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    private function createPurchaseOrder(?Company $company = null): Document
    {
        $company ??= $this->company;

        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-GR-BF-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);
    }

    private function createPoLine(
        Document $po,
        Product $product,
        string $quantity,
        string $invoiced,
        string $unitCost,
        int $lineNumber = 1,
    ): DocumentLine {
        return DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => $lineNumber,
            'description' => $product->name,
            'quantity' => $quantity,
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => $quantity,
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => $invoiced,
            'unit_price' => '5.000',
            'line_total' => bcmul($quantity, '5.000', 3),
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => $unitCost,
            'accrual_unit_cost' => $unitCost,
            'price_entry_mode' => 'unit',
        ]);
    }

    private function createMovement(
        Product $product,
        Document $po,
        string $quantity,
        string $unitCost,
        CarbonImmutable $createdAt,
        ?Company $company = null,
    ): StockMovement {
        $company ??= $this->company;

        $movement = StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => $quantity,
            'quantity_before' => '0.0000',
            'quantity_after' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => bcmul($quantity, $unitCost, 6),
            'reference' => $po->document_number,
            'reference_type' => 'Document',
            'reference_id' => $po->id,
        ]);

        $movement->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $movement;
    }
}
