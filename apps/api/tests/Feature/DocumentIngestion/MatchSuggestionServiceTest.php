<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\DocumentIngestion\Application\DTO\ExtractionResultData;
use App\Modules\DocumentIngestion\Application\Services\MatchSuggestionService;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
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
        $this->assertSame($receiptResult->purchaseOrder->lines[0]->id, $suggestions->receiptLineCandidates[0]['po_line_id']);
        $this->assertSame($receiptResult->receipt->lines[0]->id, $suggestions->receiptLineCandidates[0]['receipt_line_id']);
        $this->assertSame('4.0000', $suggestions->receiptLineCandidates[0]['uninvoiced_quantity']);
        $this->assertSame('12.500', $suggestions->receiptLineCandidates[0]['unit_price']);
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
