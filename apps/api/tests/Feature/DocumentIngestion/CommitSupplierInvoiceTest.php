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
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\DocumentIngestion\Application\Committers\SupplierInvoiceCommitter;
use App\Modules\DocumentIngestion\Application\DTO\ReviewedPayloadData;
use App\Modules\DocumentIngestion\Domain\DocumentIngestion;
use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use App\Modules\DocumentIngestion\Domain\Enums\IngestionStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\GoodsReceipt;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class CommitSupplierInvoiceTest extends TestCase
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
            'name' => 'Commit SI Tenant',
            'slug' => 'commit-si-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Commit SI Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actor = $this->user('commit-si@test.example');
        $this->actor->givePermissionTo([
            'document-ingestions.commit',
            'goods-receipt.create-standalone',
            'supplier-invoices.create-pending',
            // F-W2-14: the receipt-mapped commit path now requires the dedicated
            // supplier-invoices.manage gate (was documents.update).
            'supplier-invoices.manage',
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
            'code' => 'SI-WH',
            'name' => 'Commit SI Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Commit SI Supplier',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->setPolicy(receiptFirst: true, invoiceFirst: true);
    }

    #[Test]
    public function it_commits_a_receipt_mapped_supplier_invoice_to_a_draft_matched_invoice(): void
    {
        $product = $this->product('SI-MAPPED', '12.500');
        [$purchaseOrder, $receipt] = $this->createPostedStandaloneReceipt($product, 'si-map-receipt');
        $poLine = $purchaseOrder->lines->firstOrFail();
        $receiptLine = $receipt->lines->firstOrFail();
        $ingestion = $this->ingestion();

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product, $poLine->id));

        $response->assertCreated()
            ->assertJsonPath('data.committed_type', 'supplier_invoice');

        $invoice = Document::query()->with('lines')->findOrFail((string) $response->json('data.committed_id'));
        $line = $invoice->lines->firstOrFail();

        $this->assertSame(DocumentType::SupplierInvoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame($this->supplier->id, $invoice->partner_id);
        $this->assertSame([$purchaseOrder->id], ($invoice->payload ?? [])['supplier_invoice']['source_document_ids']);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $invoice->match_status);
        $this->assertSame($poLine->id, $line->source_line_id);
        $this->assertSame($receiptLine->id, $line->matched_receipt_line_id);
        $this->assertNotNull($line->price_match_basis);
        $this->assertSame(IngestionStatus::Committed, $ingestion->refresh()->status);
    }

    #[Test]
    public function it_commits_a_pending_supplier_invoice_and_requires_pending_permission(): void
    {
        $product = $this->product('SI-PENDING', '10.000');
        $ingestion = $this->ingestion();

        $response = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload(
                $product,
                sourceLineId: null,
                reference: 'INV-PENDING-001',
                pendingReceipt: true,
            ));

        $response->assertCreated()
            ->assertJsonPath('data.committed_type', 'supplier_invoice');

        $invoice = Document::query()->findOrFail((string) $response->json('data.committed_id'));
        $this->assertSame([], ($invoice->payload ?? [])['supplier_invoice']['source_document_ids']);
        $this->assertTrue(($invoice->payload ?? [])['supplier_invoice']['pending_receipt']);
        $this->assertSame(SupplierInvoiceMatchStatus::Unmatched, $invoice->match_status);

        $limited = $this->user('commit-si-no-pending@test.example');
        $limited->givePermissionTo('document-ingestions.commit');
        UserCompanyMembership::create([
            'user_id' => $limited->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($limited, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload(
                $product,
                sourceLineId: null,
                reference: 'INV-PENDING-002',
                pendingReceipt: true,
            ))
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_duplicate_supplier_reference_and_cross_field_source_errors(): void
    {
        $product = $this->product('SI-GUARD', '11.000');
        [$purchaseOrder] = $this->createPostedStandaloneReceipt($product, 'si-guard-receipt');
        $poLine = $purchaseOrder->lines->firstOrFail();

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product, $poLine->id, reference: 'INV-DUP-001'))
            ->assertCreated();

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product, $poLine->id, reference: 'INV-DUP-001'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DUPLICATE_SUPPLIER_REFERENCE');

        $otherSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Supplier',
            'type' => PartnerType::Supplier,
        ]);
        [$otherPo] = $this->createPostedStandaloneReceipt($product, 'si-other-supplier', $otherSupplier);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product, $otherPo->lines->firstOrFail()->id, reference: 'INV-SUPPLIER-MISMATCH'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'COMMIT_FAILED');

        $purchaseOrder->forceFill(['status' => DocumentStatus::Cancelled])->save();
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $this->payload($product, $poLine->id, reference: 'INV-CANCELLED-PO'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'COMMIT_FAILED');
    }

    #[Test]
    public function receipt_mapped_commit_requires_supplier_invoices_manage_permission(): void
    {
        // F-W2-14 / DEV-QA-027-028: the receipt-mapped (non-pending) ingestion
        // commit is a second API door into supplier-invoice creation. A holder of
        // the generic document-ingestions.commit (even WITH supplier-invoices.create-pending)
        // must still carry the dedicated supplier-invoices.manage gate — otherwise
        // the commit path becomes a privilege bypass around the store route.
        $product = $this->product('SI-PERM', '10.000');
        [$purchaseOrder] = $this->createPostedStandaloneReceipt($product, 'si-perm-receipt');
        $poLine = $purchaseOrder->lines->firstOrFail();

        $limited = $this->user('commit-si-no-manage@test.example');
        $limited->givePermissionTo([
            'document-ingestions.commit',
            'supplier-invoices.create-pending',
        ]);
        UserCompanyMembership::create([
            'user_id' => $limited->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $ingestion = $this->ingestion();

        // Without supplier-invoices.manage → 403, nothing created.
        $this->actingAs($limited, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product, $poLine->id, reference: 'INV-PERM-001'))
            ->assertForbidden();

        $this->assertSame(0, Document::query()->where('type', DocumentType::SupplierInvoice)->count());

        // Granting the dedicated gate flips the same commit to success — proving the
        // gate is supplier-invoices.manage specifically, not create-pending.
        $limited->givePermissionTo('supplier-invoices.manage');

        $this->actingAs($limited, 'sanctum')
            ->postJson("/api/v1/document-ingestions/{$ingestion->id}/commit", $this->payload($product, $poLine->id, reference: 'INV-PERM-001'))
            ->assertCreated()
            ->assertJsonPath('data.committed_type', 'supplier_invoice');

        $this->assertSame(1, Document::query()->where('type', DocumentType::SupplierInvoice)->count());
    }

    #[Test]
    public function it_normalizes_the_supplier_reference_once_for_store_and_duplicate_guard(): void
    {
        // Off-HTTP path (no TrimStrings middleware): the committer itself must
        // normalize the reference so the stored value and the duplicate-guard
        // lookup can never diverge.
        $product = $this->product('SI-TRIM', '10.000');
        $committer = app(SupplierInvoiceCommitter::class);

        $paddedPayload = ReviewedPayloadData::fromArray($this->payload(
            $product,
            sourceLineId: null,
            reference: '  INV-TRIM-001  ',
            pendingReceipt: true,
        ));

        $result = $committer->commit($this->ingestion(), $paddedPayload, $this->actor->id);

        $invoice = Document::query()->findOrFail($result->committedId);
        $this->assertSame('INV-TRIM-001', $invoice->external_document_number);

        $trimmedPayload = ReviewedPayloadData::fromArray($this->payload(
            $product,
            sourceLineId: null,
            reference: 'INV-TRIM-001',
            pendingReceipt: true,
        ));

        try {
            $committer->commit($this->ingestion(), $trimmedPayload, $this->actor->id);
            $this->fail('Expected the duplicate-reference guard to reject the trimmed reference.');
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString('DUPLICATE_SUPPLIER_REFERENCE', (string) $response->getContent());
        }
    }

    #[Test]
    public function invoice_payload_requires_vat_rate_before_service_create(): void
    {
        $product = $this->product('SI-VAT', '9.000');
        $payload = $this->payload($product, sourceLineId: null, reference: 'INV-MISSING-VAT', pendingReceipt: true);
        unset($payload['lines'][0]['vatRate']);

        $this->assertApiValidationErrors(
            $this->actingAs($this->actor, 'sanctum')
                ->postJson("/api/v1/document-ingestions/{$this->ingestion()->id}/commit", $payload),
            ['lines.0.vatRate'],
        );
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

    private function setPolicy(bool $receiptFirst, bool $invoiceFirst): void
    {
        ProcurementPolicy::query()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id],
            [
                'bill_control_mode' => BillControlMode::Received,
                'match_mode' => MatchMode::ThreeWay,
                'match_enforcement' => MatchEnforcement::Warn,
                'variance_tolerance_percent' => '2.00',
                'variance_tolerance_max_amount' => '1.000',
                'allow_receipt_first' => $receiptFirst,
                'allow_invoice_first' => $invoiceFirst,
                'invoice_first_requires_approval' => true,
            ],
        );
    }

    private function product(string $sku, string $purchasePrice): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "{$sku} product",
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'purchase_price' => $purchasePrice,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    /**
     * @return array{Document, GoodsReceipt}
     */
    private function createPostedStandaloneReceipt(Product $product, string $key, ?Partner $supplier = null): array
    {
        $supplier ??= $this->supplier;

        $result = app(StandaloneReceiptService::class)->execute(new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $supplier->id,
            locationId: $this->warehouse->id,
            actorId: $this->actor->id,
            idempotencyKey: $key,
            source: 'standalone_receipt',
            externalReference: 'BL-'.$key,
            externalDate: '2026-07-06',
            postImmediately: true,
            lines: [
                new StandaloneReceiptLineInput(
                    productId: $product->id,
                    variantId: null,
                    quantity: '3.0000',
                    freeQuantity: '0.0000',
                    unitPrice: (string) $product->purchase_price,
                    batch: null,
                ),
            ],
        ));

        return [$result->purchaseOrder->refresh()->load('lines'), $result->receipt->refresh()->load('lines')];
    }

    private function ingestion(): DocumentIngestion
    {
        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Document,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Ready,
            'storage_disk' => 's3',
            'storage_path' => 'ingestions/invoice.pdf',
            'original_filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'uploaded_by' => $this->actor->id,
        ]);

        return DocumentIngestion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'kind' => DocumentKind::SupplierInvoice,
            'status' => IngestionStatus::NeedsReview,
            'media_asset_id' => $asset->id,
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'extraction' => ['doc_kind' => 'supplier_invoice'],
            'confidence_summary' => [
                'average_confidence' => 0.98,
                'low_confidence_fields' => [],
                'reconciliation' => ['consistent' => true, 'flags' => []],
            ],
            'created_by' => $this->actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Product $product,
        ?string $sourceLineId,
        string $reference = 'INV-SCAN-001',
        bool $pendingReceipt = false,
    ): array {
        $line = [
            'productId' => $product->id,
            'quantity' => '3.0000',
            'unitPrice' => (string) $product->purchase_price,
            'vatRate' => '19.00',
        ];

        if ($sourceLineId !== null) {
            $line['sourceLineId'] = $sourceLineId;
        }

        return [
            'supplierId' => $this->supplier->id,
            'reference' => $reference,
            'documentDate' => '2026-07-07',
            'currency' => 'TND',
            'pendingReceipt' => $pendingReceipt,
            'lines' => [$line],
        ];
    }
}
