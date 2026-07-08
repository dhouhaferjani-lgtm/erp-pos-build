<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\Services\MatchSuggestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use App\Modules\Procurement\Application\StandaloneReceiptService;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MatchSuggestionServiceTest extends TestCase
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
            'name' => 'Suggestion Tenant',
            'slug' => 'suggestion-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Suggestion Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Suggestion User',
            'email' => 'suggestion-'.Str::random(8).'@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SUG-WH',
            'name' => 'Suggestion Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Pharma Distribution France SARL',
            'type' => PartnerType::Supplier,
            'vat_number' => 'FR12345678901',
            'is_active' => true,
        ]);
    }

    public function test_suggestions_rank_supplier_by_vat_product_by_sku_and_open_receipt_line_by_matchable_quantity(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->allowReceiptFirst();

        Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Pharma Distribution France Backup',
            'type' => PartnerType::Supplier,
            'vat_number' => 'FR00000000000',
            'is_active' => true,
        ]);

        $skuProduct = $this->createProduct('DOL1000-8', 'Doliprane primary sku', purchasePrice: '12.500');
        $this->createProduct('OTHER-SKU', 'Doliprane 1000mg boîte 8 comprimés', purchasePrice: '11.000');

        $receiptResult = app(StandaloneReceiptService::class)->execute(new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $this->supplier->id,
            locationId: $this->warehouse->id,
            actorId: $this->user->id,
            idempotencyKey: 'suggestion-receipt-001',
            source: 'standalone_receipt',
            externalReference: 'BL-SUG-001',
            externalDate: '2026-07-05',
            postImmediately: true,
            lines: [
                new StandaloneReceiptLineInput(
                    productId: $skuProduct->id,
                    variantId: null,
                    quantity: '4.0000',
                    freeQuantity: '0.0000',
                    unitPrice: '12.500',
                ),
            ],
        ));

        $suggestions = app(MatchSuggestionService::class)->suggest(
            $this->ingestion(),
            ExtractionResultData::from($this->fixturePayload('extraction_invoice_fr.json')),
        );

        $this->assertSame($this->supplier->id, $suggestions->supplierCandidates[0]['id']);
        $this->assertSame('vat_number', $suggestions->supplierCandidates[0]['matched_by']);
        $this->assertSame($skuProduct->id, $suggestions->productCandidates[0][0]['id']);
        $this->assertSame('sku', $suggestions->productCandidates[0][0]['matched_by']);
        $purchaseOrderLine = $receiptResult->purchaseOrder->lines->first();
        $receiptLine = $receiptResult->receipt->lines->first();
        $this->assertNotNull($purchaseOrderLine);
        $this->assertNotNull($receiptLine);
        $this->assertSame($purchaseOrderLine->id, $suggestions->receiptLineCandidates[0]['po_line_id']);
        $this->assertSame($receiptLine->id, $suggestions->receiptLineCandidates[0]['receipt_line_id']);
        $this->assertSame('4.0000', $suggestions->receiptLineCandidates[0]['uninvoiced_quantity']);
        $this->assertSame('12.500', $suggestions->receiptLineCandidates[0]['unit_price']);
    }

    public function test_receipt_line_candidates_filter_fully_invoiced_rows_before_limit(): void
    {
        $product = $this->createProduct('FILTER-SKU', 'Filter Product', purchasePrice: '8.000');
        $po = $this->createPurchaseOrder('PO-FILTER-001');
        $poLine = $this->createPurchaseOrderLine($po, $product, 1);

        for ($i = 0; $i < 3; $i++) {
            $receipt = $this->createReceipt($po, "GRN-OPEN-{$i}", now()->subDays(10 - $i));
            $this->createReceiptLine($receipt, $poLine, quantityInvoiced: '0.0000');
        }

        for ($i = 0; $i < 25; $i++) {
            $receipt = $this->createReceipt($po, "GRN-CLOSED-{$i}", now()->subMinutes($i));
            $this->createReceiptLine($receipt, $poLine, quantityInvoiced: '10.0000');
        }

        $suggestions = app(MatchSuggestionService::class)->suggest(
            $this->ingestion(),
            ExtractionResultData::from($this->fixturePayload('extraction_invoice_fr.json')),
        );

        $this->assertCount(3, $suggestions->receiptLineCandidates);
        $this->assertSame(['10.0000', '10.0000', '10.0000'], array_column($suggestions->receiptLineCandidates, 'uninvoiced_quantity'));
    }

    public function test_product_matching_uses_bounded_queries_instead_of_full_catalog_scan_per_line(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->createProduct("NO-MATCH-{$i}", "Unrelated Product {$i}", purchasePrice: '1.000');
        }
        $this->createProduct('DOL1000-8', 'Doliprane primary sku', purchasePrice: '12.500');

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(MatchSuggestionService::class)->suggest(
            $this->ingestion(),
            ExtractionResultData::from($this->fixturePayload('extraction_invoice_fr.json')),
        );

        $productQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains((string) $query['query'], 'from "products"')
        ));

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, count($productQueries));
        foreach ($productQueries as $query) {
            $this->assertStringContainsString('limit', strtolower((string) $query['query']));
        }
    }

    public function test_supplier_vat_match_normalizes_spaces_and_case(): void
    {
        $payload = $this->fixturePayload('extraction_invoice_fr.json');
        $payload['supplier']['vat_number']['value'] = 'fr 12 345 678 901';

        $suggestions = app(MatchSuggestionService::class)->suggest(
            $this->ingestion(),
            ExtractionResultData::from($payload),
        );

        $this->assertSame($this->supplier->id, $suggestions->supplierCandidates[0]['id']);
        $this->assertSame('vat_number', $suggestions->supplierCandidates[0]['matched_by']);
    }

    private function allowReceiptFirst(): void
    {
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
            'allow_receipt_first' => true,
            'allow_invoice_first' => false,
            'invoice_first_requires_approval' => true,
        ]);
    }

    private function createProduct(string $sku, string $name, string $purchasePrice): Product
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
            'purchase_price' => $purchasePrice,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    private function createPurchaseOrder(string $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '80.000',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '80.000',
            'balance_due' => '80.000',
            'is_historical' => false,
        ]);
    }

    private function createPurchaseOrderLine(Document $po, Product $product, int $lineNumber): DocumentLine
    {
        return DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'line_number' => $lineNumber,
            'description' => $product->name,
            'quantity' => '10.0000',
            'free_quantity' => '0.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '10.0000',
            'free_quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'free_quantity_invoiced' => '0.0000',
            'price_entry_mode' => 'unit',
            'is_bonus_line' => false,
            'unit_price' => '8.000',
            'discount_percent' => '0.00',
            'discount_amount' => '0.000',
            'tax_rate' => '0.00',
            'tax_amount' => '0.000',
            'tax_recoverable' => true,
            'recoverable_tax_amount' => '0.000',
            'non_recoverable_tax_amount' => '0.000',
            'line_total' => '80.000',
            'allocated_costs' => '0.000000',
            'landed_unit_cost' => '8.000000',
            'accrual_unit_cost' => '8.000000',
        ]);
    }

    private function createReceipt(Document $po, string $number, \DateTimeInterface $createdAt): GoodsReceipt
    {
        $receipt = GoodsReceipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'purchase_order_id' => $po->id,
            'receipt_number' => $number,
            'status' => GoodsReceiptStatus::Posted,
            'received_at' => $createdAt,
            'received_by' => $this->user->id,
        ]);

        $receipt->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $receipt;
    }

    private function createReceiptLine(GoodsReceipt $receipt, DocumentLine $poLine, string $quantityInvoiced): GoodsReceiptLine
    {
        $line = GoodsReceiptLine::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'goods_receipt_id' => $receipt->id,
            'po_line_id' => $poLine->id,
            'product_id' => (string) $poLine->product_id,
            'variant_id' => null,
            'received_qty' => '10.0000',
            'free_qty' => '0.0000',
            'received_unit_price' => '8.000',
            'landed_unit_cost' => '8.000000',
            'accrual_unit_cost' => '8.000000',
            'effective_unit_cost' => '8.000000',
            'movement_id' => null,
            'free_movement_id' => null,
            'quantity_invoiced' => $quantityInvoiced,
            'free_quantity_invoiced' => '0.0000',
        ]);

        $line->forceFill([
            'created_at' => $receipt->created_at,
            'updated_at' => $receipt->updated_at,
        ])->save();

        return $line;
    }

    private function ingestion(): DocumentIngestion
    {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'ingestions/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', 'suggestion-source'),
            'uploaded_by' => $this->user->id,
        ]);

        return DocumentIngestion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'kind' => DocumentKind::SupplierInvoice,
            'status' => IngestionStatus::NeedsReview,
            'media_asset_id' => $asset->id,
            'checksum' => hash('sha256', 'suggestion-source'),
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixturePayload(string $name): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/document_ingestion/'.$name));
        $this->assertIsString($json);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
