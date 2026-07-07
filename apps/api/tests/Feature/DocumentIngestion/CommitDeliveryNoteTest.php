<?php

declare(strict_types=1);

namespace Tests\Feature\DocumentIngestion;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\ProcurementPolicy;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class CommitDeliveryNoteTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $actor;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Commit BL Tenant',
            'slug' => 'commit-bl-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Commit BL Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actor = $this->user('commit-bl@test.example');
        $this->actor->givePermissionTo([
            'document-ingestions.commit',
            'goods-receipt.create-standalone',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->actor->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'BL-WH',
            'name' => 'Commit BL Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Commit BL Supplier',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->setReceiptFirstPolicy(true);
    }

    #[Test]
    public function it_commits_a_delivery_note_to_a_posted_standalone_receipt_idempotently(): void
    {
        $product = $this->product('BL-BATCH', purchasePrice: '9.750', requiresBatchTracking: true);
        $ingestion = $this->ingestion();

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product));

        $response->assertCreated()
            ->assertJsonPath('data.committed_type', 'goods_receipt');

        $receiptId = (string) $response->json('data.committed_id');
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($receiptId);
        $committed = $ingestion->refresh();

        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertNotNull($receipt->receipt_number);
        $this->assertSame('BL-SCAN-001', $receipt->external_reference);
        $this->assertSame('2026-07-06', $receipt->external_date?->toDateString());
        $this->assertSame(IngestionStatus::Committed, $committed->status);
        $this->assertSame('goods_receipt', $committed->committed_type);
        $this->assertSame($receipt->id, $committed->committed_id);
        $this->assertSame(1, Document::query()->where('type', DocumentType::PurchaseOrder)->count());
        $this->assertSame(1, StockMovement::query()->count());

        $replay = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product));

        $replay->assertOk()
            ->assertJsonPath('data.committed_id', $receipt->id);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
    }

    #[Test]
    public function it_recovers_a_committing_delivery_note_without_double_receiving(): void
    {
        $product = $this->product('BL-CRASH', purchasePrice: '8.125', requiresBatchTracking: true);
        $ingestion = $this->ingestion(status: IngestionStatus::Committing);

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product, reference: 'BL-CRASH-001'));

        $response->assertCreated()
            ->assertJsonPath('data.committed_type', 'goods_receipt');

        $this->assertSame(IngestionStatus::Committed, $ingestion->refresh()->status);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
    }

    #[Test]
    public function it_rejects_policy_permission_scope_batch_and_price_failures_cleanly(): void
    {
        $product = $this->product('BL-GUARDS', purchasePrice: '7.000', requiresBatchTracking: true);

        $this->setReceiptFirstPolicy(false);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'COMMIT_FAILED');

        $this->setReceiptFirstPolicy(true);
        $limited = $this->user('commit-bl-limited@test.example');
        $limited->givePermissionTo('document-ingestions.commit');
        UserCompanyMembership::create([
            'user_id' => $limited->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($limited, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product))
            ->assertForbidden();

        $foreignCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Foreign BL Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $foreignProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $foreignCompany->id,
            'sku' => 'BL-FOREIGN',
            'name' => 'Foreign BL product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'purchase_price' => '7.000',
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($foreignProduct))
            ->assertNotFound();

        $this->assertApiValidationErrors(
            $this->actingAs($this->actor, 'sanctum')
                ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product, batch: null)),
            ['lines.0.batch'],
        );

        $priceMissing = $this->product('BL-NO-PRICE', purchasePrice: null);
        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($priceMissing, unitPrice: null));
        $this->assertApiValidationErrors($response, ['lines.0.unitPrice']);
        $response->assertJsonPath('error.code', 'LINE_PRICE_REQUIRED');
    }

    #[Test]
    public function it_accepts_a_free_quantity_only_delivery_note_line(): void
    {
        $product = $this->product('BL-FREE', purchasePrice: '6.000');
        $ingestion = $this->ingestion();

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload(
                $product,
                quantity: '0.0000',
                freeQuantity: '2.0000',
                unitPrice: null,
                reference: 'BL-FREE-001',
            ));

        $response->assertCreated();
        $receipt = GoodsReceipt::query()->with('lines')->sole();
        $line = $receipt->lines->firstOrFail();
        $this->assertSame('0.0000', (string) $line->received_qty);
        $this->assertSame('2.0000', (string) $line->free_qty);
    }

    private function user(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
    }

    private function setReceiptFirstPolicy(bool $allowed): void
    {
        ProcurementPolicy::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id],
            [
                'bill_control_mode' => BillControlMode::Received,
                'match_mode' => MatchMode::ThreeWay,
                'match_enforcement' => MatchEnforcement::Warn,
                'variance_tolerance_percent' => '2.00',
                'variance_tolerance_max_amount' => '1.000',
                'allow_receipt_first' => $allowed,
                'allow_invoice_first' => false,
                'invoice_first_requires_approval' => true,
            ],
        );
    }

    private function product(string $sku, ?string $purchasePrice, bool $requiresBatchTracking = false): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "{$sku} product",
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $requiresBatchTracking,
            'purchase_price' => $purchasePrice,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    private function ingestion(IngestionStatus $status = IngestionStatus::NeedsReview): DocumentIngestion
    {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'ingestions/bl.pdf',
            'original_filename' => 'bl.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => $this->actor->id,
        ]);

        return DocumentIngestion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'kind' => DocumentKind::SupplierDeliveryNote,
            'status' => $status,
            'media_asset_id' => $asset->id,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'extraction' => ['doc_kind' => 'supplier_delivery_note'],
            'confidence_summary' => [
                'average_confidence' => 0.98,
                'low_confidence_fields' => [],
                'reconciliation' => ['consistent' => true, 'flags' => []],
            ],
            'created_by' => $this->actor->id,
        ]);
    }

    /**
     * @param  array{batchNumber: string, expiryDate: string}|null  $batch
     * @return array<string, mixed>
     */
    private function payload(
        Product $product,
        string $quantity = '3.0000',
        string $freeQuantity = '0.0000',
        ?string $unitPrice = '9.750',
        string $reference = 'BL-SCAN-001',
        ?array $batch = ['batchNumber' => 'LOT-SCAN-001', 'expiryDate' => '2027-01-31'],
    ): array {
        $line = [
            'productId' => $product->id,
            'quantity' => $quantity,
            'freeQuantity' => $freeQuantity,
        ];
        if ($unitPrice !== null) {
            $line['unitPrice'] = $unitPrice;
        }
        if ($batch !== null) {
            $line['batch'] = $batch;
        }

        return [
            'supplierId' => $this->supplier->id,
            'locationId' => $this->warehouse->id,
            'reference' => $reference,
            'documentDate' => '2026-07-06',
            'lines' => [$line],
        ];
    }
}
