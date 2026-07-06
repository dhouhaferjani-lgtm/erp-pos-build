<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
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
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\GoodsReceipt;
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
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * B3 — Supplier Invoice HTTP API: create / match / post + list + detail.
 *
 * All tests go through HTTP; DB fixtures are created directly (Document::create,
 * DocumentLine::create) rather than via endpoint so the test stays focused on the
 * supplier-invoice surface and does not re-test PO / goods-receipt logic.
 */
final class SupplierInvoiceApiTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'SI API Test Tenant',
            'slug' => 'si-api-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SI API Test Company',
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
            'name' => 'Test Admin',
            'email' => 'admin@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context (CompanyContextMiddleware also sets it per-request
        // but we need it for direct-DB seeding helpers below).
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier SARL',
            'type' => PartnerType::Supplier,
        ]);

        // Deterministic procurement policy.
        ProcurementPolicy::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'bill_control_mode' => BillControlMode::Received,
            'match_mode' => MatchMode::ThreeWay,
            'match_enforcement' => MatchEnforcement::Warn,
            'variance_tolerance_percent' => '2.00',
            'variance_tolerance_max_amount' => '1.000',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a PO with one line (fully received) and return [po, poLine].
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @return array{0: Document, 1: DocumentLine}
     */
    private function createPoWithReceipt(string $qty, string $unitPrice): array
    {
        $extended = bcmul($qty, $unitPrice, 3);

        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-SI-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => $extended,
            'tax_amount' => '0.000',
            'total' => $extended,
        ]);

        /** @var DocumentLine $line */
        $line = DocumentLine::create([
            'document_id' => $po->id,
            'line_number' => 1,
            'description' => 'Test product',
            'quantity' => $qty,
            'quantity_delivered' => '0.0000',
            'quantity_received' => $qty,
            'quantity_invoiced' => '0.0000',
            'unit_price' => $unitPrice,
            'line_total' => $extended,
            'allocated_costs' => '0.0000',
        ]);

        return [$po, $line];
    }

    /**
     * Build the SI store payload referencing the given PO and line.
     *
     * @param  numeric-string  $qty
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $vatRate
     * @return array<string, mixed>
     */
    private function siPayload(Document $po, DocumentLine $poLine, string $qty, string $unitPrice, string $vatRate = '19.00'): array
    {
        return [
            'partner_id' => $this->supplier->id,
            'source_document_id' => $po->id,
            'currency' => 'TND',
            'issue_date' => now()->toDateString(),
            'lines' => [
                [
                    'source_line_id' => $poLine->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $vatRate,
                ],
            ],
        ];
    }

    private function enableInvoiceFirst(bool $requiresApproval = true): void
    {
        ProcurementPolicy::query()
            ->where('company_id', $this->company->id)
            ->update([
                'allow_invoice_first' => true,
                'invoice_first_requires_approval' => $requiresApproval,
            ]);
    }

    private function enableReceiptFirst(): void
    {
        ProcurementPolicy::query()
            ->where('company_id', $this->company->id)
            ->update(['allow_receipt_first' => true]);
    }

    private function createLocation(string $code): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $code.' warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    private function createProduct(string $sku, bool $requiresBatchTracking = false): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $sku.' product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $requiresBatchTracking,
            'cost_price' => '0.000000',
            'last_purchase_cost' => '0.000000',
        ]);
    }

    /**
     * @return array{Document, GoodsReceipt}
     */
    /**
     * @param  array{batch_number: string, expiry_date: string, manufacturing_date?: string}|null  $batch
     * @return array{Document, GoodsReceipt}
     */
    private function createPostedStandaloneReceipt(Product $product, Location $location, string $key, string $qty, string $unitPrice, ?array $batch = null): array
    {
        $result = app(StandaloneReceiptService::class)->execute(new StandaloneReceiptInput(
            companyId: $this->company->id,
            supplierId: $this->supplier->id,
            locationId: $location->id,
            actorId: $this->user->id,
            idempotencyKey: $key,
            source: 'standalone_receipt',
            externalReference: 'BL-'.$key,
            externalDate: '2026-07-05',
            postImmediately: true,
            lines: [
                new StandaloneReceiptLineInput(
                    productId: $product->id,
                    variantId: null,
                    quantity: $qty,
                    freeQuantity: '0.0000',
                    unitPrice: $unitPrice,
                    batch: $batch,
                ),
            ],
        ));

        return [$result->purchaseOrder, $result->receipt];
    }

    private function createPosterWithoutInvoiceFirstApproval(): User
    {
        $poster = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Supplier Invoice Poster',
            'email' => 'si-poster@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $poster->id,
            'company_id' => $this->company->id,
            'role' => 'accountant',
        ]);
        $poster->givePermissionTo('documents.update');

        return $poster;
    }

    private function createDocumentsUpdateUser(string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Documents Writer',
            'email' => $email,
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'accountant',
        ]);

        $user->givePermissionTo('documents.update');

        return $user;
    }

    // -------------------------------------------------------------------------
    // 1. store: creates DRAFT + runs matcher (E2E through HTTP)
    // -------------------------------------------------------------------------

    public function test_store_creates_supplier_invoice_in_draft_with_match_status(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '6.0000', '100.000', '19.00'));

        $response->assertCreated();

        $data = $response->json('data');
        $this->assertSame('supplier_invoice', $data['type'] ?? $data['document_type'] ?? null);
        $this->assertSame(DocumentStatus::Draft->value, $data['status']);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched->value, $data['match_status']);
        $this->assertSame($po->id, $data['source_document_id']);

        // Persisted in DB
        /** @var Document $si */
        $si = Document::where('type', DocumentType::SupplierInvoice)->where('company_id', $this->company->id)->first();
        $this->assertNotNull($si);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $si->match_status);
        $this->assertSame(DocumentStatus::Draft, $si->status);
    }

    public function test_store_accepts_multiple_purchase_orders_and_persists_full_source_list(): void
    {
        [$firstPo, $firstLine] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo, $secondLine] = $this->createPoWithReceipt('6.0000', '60.000');

        $payload = [
            'partner_id' => $this->supplier->id,
            'source_document_ids' => [$secondPo->id, $firstPo->id],
            'currency' => 'TND',
            'issue_date' => now()->toDateString(),
            'lines' => [
                [
                    'source_line_id' => $firstLine->id,
                    'quantity' => '4.0000',
                    'unit_price' => '50.000',
                    'vat_rate' => '0.00',
                ],
                [
                    'source_line_id' => $secondLine->id,
                    'quantity' => '6.0000',
                    'unit_price' => '60.000',
                    'vat_rate' => '0.00',
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.source_document_id', $secondPo->id);

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertSame($secondPo->id, $invoice->source_document_id);
        $this->assertSame(
            [$secondPo->id, $firstPo->id],
            $invoice->payload['supplier_invoice']['source_document_ids'] ?? null,
        );
        $this->assertSame(2, $invoice->lines()->count());
    }

    public function test_related_documents_include_multi_po_invoice_from_secondary_purchase_order(): void
    {
        [$firstPo, $firstLine] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo, $secondLine] = $this->createPoWithReceipt('6.0000', '60.000');

        /** @var Document $invoice */
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $firstPo->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-MULTI-RELATED',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '560.000',
            'tax_amount' => '0.000',
            'total' => '560.000',
            'payload' => [
                'supplier_invoice' => [
                    'source_document_ids' => [$firstPo->id, $secondPo->id],
                ],
            ],
            'match_status' => SupplierInvoiceMatchStatus::Matched,
        ]);
        foreach ([$firstLine, $secondLine] as $idx => $line) {
            DocumentLine::create([
                'document_id' => $invoice->id,
                'line_number' => $idx + 1,
                'description' => 'Multi PO invoice line',
                'quantity' => $line->quantity_received,
                'quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => $line->unit_price,
                'line_total' => bcmul($line->quantity_received, $line->unit_price, 3),
                'tax_amount' => '0.000',
                'tax_recoverable' => true,
                'recoverable_tax_amount' => '0.000',
                'non_recoverable_tax_amount' => '0.000',
                'allocated_costs' => '0.0000',
                'source_line_id' => $line->id,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/documents/{$secondPo->id}/related");

        $response->assertOk();
        $descendantIds = collect($response->json('data.descendants'))->pluck('id')->all();
        $this->assertContains($invoice->id, $descendantIds);
    }

    public function test_related_documents_for_multi_po_invoice_include_all_source_purchase_orders_once(): void
    {
        [$firstPo, $firstLine] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo, $secondLine] = $this->createPoWithReceipt('6.0000', '60.000');

        /** @var Document $invoice */
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'source_document_id' => $firstPo->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-MULTI-ANCESTORS',
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '560.000',
            'tax_amount' => '0.000',
            'total' => '560.000',
            'payload' => [
                'supplier_invoice' => [
                    'source_document_ids' => [$firstPo->id, $secondPo->id, $firstPo->id],
                ],
            ],
            'match_status' => SupplierInvoiceMatchStatus::Matched,
        ]);
        foreach ([$firstLine, $secondLine] as $idx => $line) {
            DocumentLine::create([
                'document_id' => $invoice->id,
                'line_number' => $idx + 1,
                'description' => 'Multi PO invoice line',
                'quantity' => $line->quantity_received,
                'quantity_received' => '0.0000',
                'quantity_invoiced' => '0.0000',
                'unit_price' => $line->unit_price,
                'line_total' => bcmul($line->quantity_received, $line->unit_price, 3),
                'tax_amount' => '0.000',
                'tax_recoverable' => true,
                'recoverable_tax_amount' => '0.000',
                'non_recoverable_tax_amount' => '0.000',
                'allocated_costs' => '0.0000',
                'source_line_id' => $line->id,
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/documents/{$invoice->id}/related");

        $response->assertOk();
        $ancestorIds = collect($response->json('data.ancestors'))->pluck('id')->all();
        $sourceAncestorIds = array_values(array_filter(
            $ancestorIds,
            fn (string $id): bool => in_array($id, [$firstPo->id, $secondPo->id], true),
        ));
        $this->assertSame([$firstPo->id, $secondPo->id], $sourceAncestorIds);
    }

    public function test_related_documents_cycle_guard_does_not_return_the_starting_document_as_descendant(): void
    {
        [$firstPo] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo] = $this->createPoWithReceipt('6.0000', '60.000');

        $firstPo->forceFill(['source_document_id' => $secondPo->id])->save();
        $secondPo->forceFill(['source_document_id' => $firstPo->id])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/documents/{$firstPo->id}/related");

        $response->assertOk();
        $descendantIds = collect($response->json('data.descendants'))->pluck('id')->all();
        $this->assertContains($secondPo->id, $descendantIds);
        $this->assertNotContains($firstPo->id, $descendantIds);
    }

    public function test_store_rejects_source_document_ids_with_cross_supplier_purchase_orders(): void
    {
        [$firstPo, $firstLine] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo] = $this->createPoWithReceipt('6.0000', '60.000');
        $otherSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Multi PO Supplier',
            'type' => PartnerType::Supplier,
        ]);
        $secondPo->forceFill(['partner_id' => $otherSupplier->id])->save();

        $payload = $this->siPayload($firstPo, $firstLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [$firstPo->id, $secondPo->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['partner_id']);
    }

    public function test_store_rejects_source_document_ids_with_cross_currency_purchase_orders(): void
    {
        [$firstPo, $firstLine] = $this->createPoWithReceipt('4.0000', '50.000');
        [$secondPo] = $this->createPoWithReceipt('6.0000', '60.000');
        $secondPo->forceFill(['currency' => 'EUR'])->save();

        $payload = $this->siPayload($firstPo, $firstLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [$firstPo->id, $secondPo->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['currency']);
    }

    public function test_store_rejects_empty_source_document_ids(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('4.0000', '50.000');
        $payload = $this->siPayload($po, $poLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['source_document_ids']);
    }

    public function test_store_rejects_duplicate_source_document_ids(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('4.0000', '50.000');
        $payload = $this->siPayload($po, $poLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [$po->id, $po->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
    }

    public function test_store_rejects_non_purchase_order_source_document_id(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('4.0000', '50.000');
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-NON-PO-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '50.000',
            'tax_amount' => '0.000',
            'total' => '50.000',
        ]);

        $payload = $this->siPayload($po, $poLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [$invoice->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['source_document_ids.0']);
    }

    public function test_store_rejects_cancelled_purchase_order_source_document_id(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('4.0000', '50.000');
        $po->forceFill(['status' => DocumentStatus::Cancelled])->save();

        $payload = $this->siPayload($po, $poLine, '4.0000', '50.000', '0.00');
        unset($payload['source_document_id']);
        $payload['source_document_ids'] = [$po->id];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['source_document_ids.0']);
    }

    public function test_store_invoice_first_delivered_creates_receipt_and_links_invoice_lines_to_auto_po_lines(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $location = $this->createLocation('SI-IF-WH');
        $product = $this->createProduct('SI-IF-PRODUCT');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-delivered-001',
                'external_reference' => 'BL-IF-001',
                'external_date' => '2026-07-05',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '12.345',
                    'vat_rate' => '0.00',
                ]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', DocumentStatus::Draft->value);
        $response->assertJsonPath('data.match_status', SupplierInvoiceMatchStatus::Matched->value);

        /** @var Document $autoPo */
        $autoPo = Document::query()
            ->where('type', DocumentType::PurchaseOrder)
            ->where('company_id', $this->company->id)
            ->sole();
        $autoPo->load('lines');

        $this->assertSame('invoice_first', $autoPo->payload['auto_generated']['source'] ?? null);
        $this->assertSame(DocumentStatus::Received, $autoPo->status);
        $this->assertSame('12.345', (string) $autoPo->lines[0]->unit_price);
        $this->assertSame('12.345000', (string) $autoPo->lines[0]->landed_unit_cost);

        /** @var GoodsReceipt $receipt */
        $receipt = GoodsReceipt::query()->with('lines')->sole();
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertSame($autoPo->id, $receipt->purchase_order_id);
        $this->assertSame('BL-IF-001', $receipt->external_reference);
        $this->assertSame('12.345000', (string) $receipt->lines[0]->accrual_unit_cost);
        $this->assertNull($receipt->lines[0]->received_unit_price);

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->sole();
        $this->assertSame($autoPo->id, $invoice->source_document_id);
        $this->assertSame([$autoPo->id], $invoice->payload['supplier_invoice']['source_document_ids'] ?? null);
        $this->assertSame($autoPo->lines[0]->id, $invoice->lines[0]->source_line_id);
        $this->assertSame($receipt->lines[0]->id, $invoice->lines[0]->matched_receipt_line_id);
        $this->assertSame('12.345000', (string) $invoice->lines[0]->price_match_basis);
    }

    public function test_store_invoice_first_delivered_threads_batch_data_for_batch_tracked_products(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $location = $this->createLocation('SI-IF-BATCH-WH');
        $product = $this->createProduct('SI-IF-BATCH-PRODUCT', true);

        $withoutBatch = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-batch-missing-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '12.345',
                    'vat_rate' => '0.00',
                ]],
            ]);

        $withoutBatch->assertUnprocessable();

        $withBatch = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-batch-ok-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '12.345',
                    'vat_rate' => '0.00',
                    'batch' => [
                        'batch_number' => 'IF-BATCH-001',
                        'expiry_date' => '2027-07-05',
                        'manufacturing_date' => '2026-07-01',
                    ],
                ]],
            ]);

        $withBatch->assertCreated();

        $batch = Batch::query()
            ->where('product_id', $product->id)
            ->where('batch_number', 'IF-BATCH-001')
            ->first();
        $this->assertNotNull($batch);

        $this->assertSame(1, DocumentLine::query()->where('batch_id', $batch->id)->count());
    }

    public function test_store_invoice_first_delivered_replay_returns_existing_invoice_without_duplicates(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $location = $this->createLocation('SI-IF-IDEM-WH');
        $product = $this->createProduct('SI-IF-IDEM-PRODUCT');

        $payload = [
            'partner_id' => $this->supplier->id,
            'currency' => 'TND',
            'issue_date' => '2026-07-05',
            'invoice_first_delivered' => true,
            'location_id' => $location->id,
            'idempotency_key' => 'invoice-first-delivered-replay-001',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '2.0000',
                'unit_price' => '12.345',
                'vat_rate' => '0.00',
            ]],
        ];

        $first = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/supplier-invoices', $payload);
        $second = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/supplier-invoices', $payload);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Document::query()->where('type', DocumentType::SupplierInvoice)->where('company_id', $this->company->id)->count());
        $this->assertSame(1, Document::query()->where('type', DocumentType::PurchaseOrder)->where('company_id', $this->company->id)->count());
        $this->assertSame(1, GoodsReceipt::query()->where('company_id', $this->company->id)->count());
    }

    public function test_store_invoice_first_create_branches_require_pending_and_standalone_permissions(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $location = $this->createLocation('SI-IF-PERM-WH');
        $product = $this->createProduct('SI-IF-PERM-PRODUCT');
        $writer = $this->createDocumentsUpdateUser('writer-si-permissions@test.example');

        $normalPo = $this->createPoWithReceipt('1.0000', '5.000');
        $normal = $this->actingAs($writer, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($normalPo[0], $normalPo[1], '1.0000', '5.000', '0.00'));
        $normal->assertCreated();

        $pendingPayload = [
            'partner_id' => $this->supplier->id,
            'currency' => 'TND',
            'issue_date' => '2026-07-05',
            'pending_receipt' => true,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '1.0000',
                'unit_price' => '5.000',
                'vat_rate' => '0.00',
            ]],
        ];
        $deliveredPayload = [
            'partner_id' => $this->supplier->id,
            'currency' => 'TND',
            'issue_date' => '2026-07-05',
            'invoice_first_delivered' => true,
            'location_id' => $location->id,
            'idempotency_key' => 'invoice-first-permission-001',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '1.0000',
                'unit_price' => '5.000',
                'vat_rate' => '0.00',
            ]],
        ];

        $this->actingAs($writer, 'sanctum')->postJson('/api/v1/supplier-invoices', $pendingPayload)->assertForbidden();
        $this->actingAs($writer, 'sanctum')->postJson('/api/v1/supplier-invoices', $deliveredPayload)->assertForbidden();

        $writer->givePermissionTo('supplier-invoices.create-pending');
        $this->actingAs($writer, 'sanctum')->postJson('/api/v1/supplier-invoices', $pendingPayload)->assertCreated();
        $this->actingAs($writer, 'sanctum')->postJson('/api/v1/supplier-invoices', $deliveredPayload)->assertForbidden();

        $writer->givePermissionTo('goods-receipt.create-standalone');
        $this->actingAs($writer, 'sanctum')->postJson('/api/v1/supplier-invoices', $deliveredPayload)->assertCreated();
    }

    public function test_post_invoice_first_delivered_posts_zero_ppv_gl_legs(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst(requiresApproval: true);
        $location = $this->createLocation('SI-IF-GL-WH');
        $product = $this->createProduct('SI-IF-GL-PRODUCT');

        $store = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-delivered-gl-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '10.000',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $store->assertCreated();
        $invoiceId = $store->json('data.id');
        $this->assertNotNull($invoiceId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Posted->value);

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();
        $entry->load('lines');

        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $ppvExpenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $ppvIncomeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

        $dr408 = $entry->lines->firstWhere('account_id', $grirAccount->id);
        $this->assertNotNull($dr408);
        $this->assertSame('20.000', $dr408->debit);
        $this->assertSame('0.000', $dr408->credit);

        $cr401 = $entry->lines->firstWhere('account_id', $payableAccount->id);
        $this->assertNotNull($cr401);
        $this->assertSame('0.000', $cr401->debit);
        $this->assertSame('20.000', $cr401->credit);
        $this->assertSame($this->supplier->id, $cr401->partner_id);

        $this->assertNull($entry->lines->firstWhere('account_id', $ppvExpenseAccount->id));
        $this->assertNull($entry->lines->firstWhere('account_id', $ppvIncomeAccount->id));
    }

    public function test_store_pending_receipt_supplier_invoice_persists_null_sources_and_post_is_blocked(): void
    {
        $this->enableInvoiceFirst();
        $product = $this->createProduct('SI-PENDING-PRODUCT');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'supplier_reference' => 'FAC-PENDING-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '3.0000',
                    'unit_price' => '8.125',
                    'vat_rate' => '0.00',
                ]],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', DocumentStatus::Draft->value);
        $response->assertJsonPath('data.match_status', SupplierInvoiceMatchStatus::Unmatched->value);
        $response->assertJsonPath('data.source_document_id', null);
        $response->assertJsonPath('data.pending_receipt', true);
        $response->assertJsonPath('data.lines.0.source_line_id', null);

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->sole();
        $this->assertNull($invoice->source_document_id);
        $this->assertSame([], $invoice->payload['supplier_invoice']['source_document_ids'] ?? null);
        $this->assertTrue($invoice->payload['supplier_invoice']['pending_receipt'] ?? false);
        $this->assertNull($invoice->lines[0]->source_line_id);
        $this->assertNull($invoice->lines[0]->price_match_basis);
        $this->assertNull($invoice->lines[0]->matched_receipt_line_id);

        $listResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?pending_receipt=1');

        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.0.id', $invoice->id);
        $listResponse->assertJsonPath('data.0.pending_receipt', true);

        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/post");

        $postResponse->assertUnprocessable();
        $postResponse->assertJsonPath('error.message', 'PENDING_RECEIPT_UNLINKED');
    }

    public function test_store_pending_receipt_supplier_invoice_rejects_when_invoice_first_policy_is_disabled(): void
    {
        $product = $this->createProduct('SI-PENDING-POLICY-OFF');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '8.125',
                    'vat_rate' => '0.00',
                ]],
            ]);

        $this->assertApiValidationEnvelope($response);
        $this->assertJsonValidationErrors($response, ['pending_receipt']);
    }

    public function test_link_receipts_clears_pending_invoice_and_stamps_match_snapshots(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $this->enableReceiptFirst();
        $location = $this->createLocation('SI-LINK-WH');
        $product = $this->createProduct('SI-LINK-PRODUCT');
        [$purchaseOrder, $receipt] = $this->createPostedStandaloneReceipt($product, $location, 'link-001', '3.0000', '8.125');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '3.0000',
                    'unit_price' => '8.125',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $storeResponse->assertCreated();

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertTrue($invoice->payload['supplier_invoice']['pending_receipt'] ?? false);
        $this->assertNull($invoice->lines[0]->source_line_id);
        $this->assertNull($invoice->lines[0]->price_match_basis);
        $this->assertNull($invoice->lines[0]->matched_receipt_line_id);

        $receipt->load('lines');
        $linkResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/link-receipts", [
                'links' => [[
                    'invoice_line_id' => $invoice->lines[0]->id,
                    'receipt_line_id' => $receipt->lines[0]->id,
                ]],
            ]);

        $linkResponse->assertOk();
        $linkResponse->assertJsonPath('data.match_status', SupplierInvoiceMatchStatus::Matched->value);
        $linkResponse->assertJsonPath('data.pending_receipt', false);

        $invoice->refresh();
        $invoice->load('lines');
        $this->assertFalse($invoice->payload['supplier_invoice']['pending_receipt'] ?? true);
        $this->assertSame($purchaseOrder->id, $invoice->source_document_id);
        $this->assertSame([$purchaseOrder->id], $invoice->payload['supplier_invoice']['source_document_ids'] ?? null);
        $this->assertSame($purchaseOrder->lines[0]->id, $invoice->lines[0]->source_line_id);
        $this->assertSame('8.125000', (string) $invoice->lines[0]->price_match_basis);
        $this->assertSame($receipt->lines[0]->id, $invoice->lines[0]->matched_receipt_line_id);
        $this->assertSame(SupplierInvoiceMatchStatus::Matched, $invoice->match_status);
    }

    public function test_link_receipts_rejects_product_mismatch(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $this->enableReceiptFirst();
        $location = $this->createLocation('SI-LINK-MISMATCH-WH');
        $invoiceProduct = $this->createProduct('SI-LINK-INVOICE-PRODUCT');
        $receiptProduct = $this->createProduct('SI-LINK-RECEIPT-PRODUCT');
        [, $receipt] = $this->createPostedStandaloneReceipt($receiptProduct, $location, 'link-mismatch-001', '3.0000', '8.125');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'lines' => [[
                    'product_id' => $invoiceProduct->id,
                    'quantity' => '3.0000',
                    'unit_price' => '8.125',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $storeResponse->assertCreated();

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->latest('created_at')
            ->firstOrFail();
        $receipt->load('lines');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/link-receipts", [
                'links' => [[
                    'invoice_line_id' => $invoice->lines[0]->id,
                    'receipt_line_id' => $receipt->lines[0]->id,
                ]],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.message', 'LINK_PRODUCT_MISMATCH');
    }

    public function test_link_receipts_requires_pending_draft_invoice(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableReceiptFirst();
        $location = $this->createLocation('SI-LINK-NOT-PENDING-WH');
        $product = $this->createProduct('SI-LINK-NOT-PENDING-PRODUCT');
        [$po, $receipt] = $this->createPostedStandaloneReceipt($product, $location, 'link-not-pending-001', '3.0000', '8.125');
        $po->load('lines');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $po->lines[0], '3.0000', '8.125', '0.00'));
        $storeResponse->assertCreated();

        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->latest('created_at')
            ->firstOrFail();
        $receipt->load('lines');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/link-receipts", [
                'links' => [[
                    'invoice_line_id' => $invoice->lines[0]->id,
                    'receipt_line_id' => $receipt->lines[0]->id,
                ]],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.message', 'LINK_NOT_PENDING');
    }

    public function test_partial_link_keeps_pending_receipt_until_all_lines_are_linked(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $this->enableReceiptFirst();
        $location = $this->createLocation('SI-LINK-PARTIAL-WH');
        $firstProduct = $this->createProduct('SI-LINK-PARTIAL-A');
        $secondProduct = $this->createProduct('SI-LINK-PARTIAL-B');
        [, $firstReceipt] = $this->createPostedStandaloneReceipt($firstProduct, $location, 'link-partial-a', '3.0000', '8.125');
        $this->createPostedStandaloneReceipt($secondProduct, $location, 'link-partial-b', '4.0000', '9.125');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'lines' => [
                    [
                        'product_id' => $firstProduct->id,
                        'quantity' => '3.0000',
                        'unit_price' => '8.125',
                        'vat_rate' => '0.00',
                    ],
                    [
                        'product_id' => $secondProduct->id,
                        'quantity' => '4.0000',
                        'unit_price' => '9.125',
                        'vat_rate' => '0.00',
                    ],
                ],
            ]);
        $storeResponse->assertCreated();

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->latest('created_at')
            ->firstOrFail();
        $firstReceipt->load('lines');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/link-receipts", [
                'links' => [[
                    'invoice_line_id' => $invoice->lines[0]->id,
                    'receipt_line_id' => $firstReceipt->lines[0]->id,
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.pending_receipt', true);

        $invoice->refresh();
        $invoice->load('lines');
        $this->assertTrue($invoice->payload['supplier_invoice']['pending_receipt'] ?? false);
        $this->assertNotNull($invoice->lines[0]->source_line_id);
        $this->assertNull($invoice->lines[1]->source_line_id);
    }

    public function test_pending_link_receipts_then_post_consumes_receipt_and_clears_gr_ir(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst();
        $this->enableReceiptFirst();
        $location = $this->createLocation('SI-LINK-POST-WH');
        $product = $this->createProduct('SI-LINK-POST-PRODUCT');
        [$purchaseOrder, $receipt] = $this->createPostedStandaloneReceipt($product, $location, 'link-post-001', '3.0000', '8.125');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'pending_receipt' => true,
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '3.0000',
                    'unit_price' => '8.125',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $storeResponse->assertCreated();

        /** @var Document $invoice */
        $invoice = Document::query()
            ->where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->latest('created_at')
            ->firstOrFail();
        $receipt->load('lines');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/link-receipts", [
                'links' => [[
                    'invoice_line_id' => $invoice->lines[0]->id,
                    'receipt_line_id' => $receipt->lines[0]->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.pending_receipt', false);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoice->id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', DocumentStatus::Posted->value);

        $this->assertSame('3.0000', GoodsReceipt::query()->with('lines')->findOrFail($receipt->id)->lines[0]->quantity_invoiced);
        $this->assertSame('3.0000', DocumentLine::query()->findOrFail($purchaseOrder->lines[0]->id)->quantity_invoiced);

        $entry = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $entry->load('lines');

        $debit = '0.000';
        $credit = '0.000';
        foreach ($entry->lines as $line) {
            $debit = bcadd($debit, $line->debit, 3);
            $credit = bcadd($credit, $line->credit, 3);
        }
        $this->assertSame('0.000', bcsub($debit, $credit, 3));

        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $net408 = JournalLine::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $grirAccount->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net');
        $this->assertSame('0.000', number_format((float) $net408, 3, '.', ''));

        $invoice->refresh();
        $this->assertSame(DocumentStatus::Posted, $invoice->status);
    }

    public function test_post_invoice_first_supplier_invoice_requires_approval_permission_when_policy_requires_it(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst(requiresApproval: true);
        $location = $this->createLocation('SI-APPROVAL-WH');
        $product = $this->createProduct('SI-APPROVAL-PRODUCT');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-approval-001',
                'external_reference' => 'BL-IF-APPROVAL-001',
                'external_date' => '2026-07-05',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '10.000',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $storeResponse->assertCreated();
        $invoiceId = $storeResponse->json('data.id');
        $this->assertNotNull($invoiceId);

        $poster = $this->createPosterWithoutInvoiceFirstApproval();

        $blocked = $this->actingAs($poster, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoiceId}/post");

        $blocked->assertUnprocessable();
        $blocked->assertJsonPath('error.message', 'INVOICE_FIRST_APPROVAL_REQUIRED');
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'supplier_invoice')->where('source_id', $invoiceId)->count());
        /** @var Document $blockedInvoice */
        $blockedInvoice = Document::query()->with('lines')->findOrFail($invoiceId);
        $this->assertSame('0.0000', DocumentLine::query()->findOrFail($blockedInvoice->lines[0]->source_line_id)->quantity_invoiced);

        $poster->givePermissionTo('supplier-invoices.approve-invoice-first');

        $posted = $this->actingAs($poster, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoiceId}/post");

        $posted->assertOk();
        $posted->assertJsonPath('data.status', DocumentStatus::Posted->value);
    }

    public function test_post_invoice_first_missing_approval_permission_record_returns_domain_error(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->enableInvoiceFirst(requiresApproval: true);
        $location = $this->createLocation('SI-APPROVAL-MISSING-WH');
        $product = $this->createProduct('SI-APPROVAL-MISSING-PRODUCT');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', [
                'partner_id' => $this->supplier->id,
                'currency' => 'TND',
                'issue_date' => '2026-07-05',
                'invoice_first_delivered' => true,
                'location_id' => $location->id,
                'idempotency_key' => 'invoice-first-missing-permission-record-001',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '2.0000',
                    'unit_price' => '10.000',
                    'vat_rate' => '0.00',
                ]],
            ]);
        $storeResponse->assertCreated();
        $invoiceId = $storeResponse->json('data.id');
        $this->assertNotNull($invoiceId);

        Permission::query()
            ->where('name', 'supplier-invoices.approve-invoice-first')
            ->where('guard_name', 'sanctum')
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $poster = $this->createDocumentsUpdateUser('si-missing-approval-permission@test.example');

        $response = $this->actingAs($poster, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$invoiceId}/post");

        $response->assertUnprocessable();
        $response->assertJsonPath('error.message', 'INVOICE_FIRST_APPROVAL_REQUIRED');
    }

    // -------------------------------------------------------------------------
    // 2. post happy path: 200, status=posted, balanced JE exists
    // -------------------------------------------------------------------------

    public function test_post_returns_200_and_creates_gr_ir_journal_entry(): void
    {
        // Seed chart of accounts so the GL posting can find system accounts.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        // Accrue the GR-IR 408 entry (normally done by GoodsReceiptService).
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '10.0000',
            '100.000',
            'TND',
        );

        // Store SI first.
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '10.0000', '100.000', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');
        $this->assertNotNull($siId);

        // Post.
        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertOk();

        $data = $postResponse->json('data');
        $this->assertSame(DocumentStatus::Posted->value, $data['status']);

        // Balanced JE must exist.
        $jeExists = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $siId)
            ->exists();
        $this->assertTrue($jeExists, 'Supplier invoice journal entry should have been created');

        // Document status is persisted.
        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertSame(DocumentStatus::Posted, $si->status);
    }

    // -------------------------------------------------------------------------
    // 3. post blocked: over-invoice → 422, no JE, status unchanged
    // -------------------------------------------------------------------------

    public function test_post_blocked_on_over_invoice_returns_422(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // PO has qty=5 received, but SI invoices qty=10 (over-clear).
        [$po, $poLine] = $this->createPoWithReceipt('5.0000', '100.000');

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '5.0000',
            '100.000',
            'TND',
        );

        // Store SI with qty=10 (exceeds received).
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '10.0000', '100.000', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        // Post attempt should be blocked.
        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertUnprocessable(); // 422

        // No JE created.
        $jeCount = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $siId)
            ->count();
        $this->assertSame(0, $jeCount, 'No journal entry should exist after blocked post');

        // Status unchanged (still draft).
        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertSame(DocumentStatus::Draft, $si->status);
    }

    // -------------------------------------------------------------------------
    // 4. index: paginated list, filters work
    // -------------------------------------------------------------------------

    public function test_index_returns_paginated_list(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        // Create 2 supplier invoices.
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '4.0000', '50.000'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta']);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filters_by_partner_id(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        // SI belonging to our supplier.
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));

        // SI belonging to a different (also-supplier) partner — but same PO
        // (we can't easily use a different PO without another partner on the PO,
        // so just test that the filter returns the correct count when filtering by
        // the supplier's ID and when filtering by a non-existent UUID).
        $noMatch = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?partner_id='.Str::uuid());

        $noMatch->assertOk();
        $this->assertCount(0, $noMatch->json('data'));

        $match = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?partner_id='.$this->supplier->id);

        $match->assertOk();
        $this->assertCount(1, $match->json('data'));
    }

    public function test_index_filters_by_status(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));

        $draftFilter = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?status=draft');
        $draftFilter->assertOk();
        $this->assertCount(1, $draftFilter->json('data'));

        $postedFilter = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?status=posted');
        $postedFilter->assertOk();
        $this->assertCount(0, $postedFilter->json('data'));
    }

    public function test_index_filters_by_search_partner_name_or_document_number(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));

        // Partner-name match — supplier is "Test Supplier SARL".
        $byName = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?search=Supplier');
        $byName->assertOk();
        $this->assertCount(1, $byName->json('data'));

        // Document-number match — pull the generated number from the list, then
        // search by it to confirm the shared document_number path still works
        // through the SupplierInvoice override.
        $all = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices');
        $number = $all->json('data.0.number');
        $this->assertNotEmpty($number);

        $byNumber = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?search='.urlencode((string) $number));
        $byNumber->assertOk();
        $this->assertCount(1, $byNumber->json('data'));

        // No match.
        $none = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?search=zzzNoSuchInvoice');
        $none->assertOk();
        $this->assertCount(0, $none->json('data'));
    }

    // -------------------------------------------------------------------------
    // 5. show: detail shape matches contract
    // -------------------------------------------------------------------------

    public function test_show_returns_detail_with_match_block(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '6.0000', '100.000', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        $showResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/supplier-invoices/{$siId}");

        $showResponse->assertOk();

        $data = $showResponse->json('data');

        // Header fields.
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('match_status', $data);
        $this->assertArrayHasKey('currency', $data);

        // Source PO reference.
        $this->assertArrayHasKey('source_purchase_order', $data);
        $this->assertSame($po->id, $data['source_purchase_order']['id']);

        // Match block.
        $this->assertArrayHasKey('match', $data);
        $this->assertArrayHasKey('status', $data['match']);
        $this->assertArrayHasKey('per_line', $data['match']);
        $this->assertNotEmpty($data['match']['per_line']);

        $perLine = $data['match']['per_line'][0];
        $this->assertSame($poLine->id, $perLine['po_line_id']);
        $this->assertArrayHasKey('ordered', $perLine);
        $this->assertArrayHasKey('received', $perLine);
        $this->assertArrayHasKey('invoiced', $perLine);
        $this->assertArrayHasKey('matchable', $perLine);
        $this->assertArrayHasKey('price_variance', $perLine);

        // Lines.
        $this->assertArrayHasKey('lines', $data);
        $this->assertNotEmpty($data['lines']);
        $line = $data['lines'][0];
        $this->assertArrayHasKey('source_line_id', $line);
        $this->assertArrayHasKey('quantity', $line);
        $this->assertArrayHasKey('unit_price', $line);
        $this->assertArrayHasKey('vat_rate', $line);
    }

    public function test_show_returns_balance_due_for_partially_paid_supplier_invoice(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '6.0000', '100.000', '0.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');
        $this->assertIsString($siId);

        /** @var Document $supplierInvoice */
        $supplierInvoice = Document::query()->findOrFail($siId);
        $supplierInvoice->status = DocumentStatus::Posted;
        $supplierInvoice->balance_due = '400.000';
        $supplierInvoice->save();

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'amount' => '200.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::WebAdmin,
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $supplierInvoice->id,
            'amount' => '200.000',
        ]);

        $showResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/supplier-invoices/{$siId}");

        $showResponse->assertOk();
        $this->assertSame('400.000', $showResponse->json('data.balance_due'));
    }

    // -------------------------------------------------------------------------
    // 6. match endpoint: re-runs matcher and returns updated block
    // -------------------------------------------------------------------------

    public function test_match_endpoint_returns_match_block(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '6.0000', '100.000'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        $matchResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/match");

        $matchResponse->assertOk();

        $data = $matchResponse->json('data');
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('per_line', $data);
    }

    // -------------------------------------------------------------------------
    // 7. authz: missing documents.update → 403 on write operations
    // -------------------------------------------------------------------------

    public function test_store_requires_documents_update_permission(): void
    {
        // Use the 'viewer' role which has documents.view but NOT documents.update.
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $viewer->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '5.0000', '50.000'));

        $response->assertForbidden();
    }

    public function test_post_requires_documents_update_permission(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '5.0000', '50.000'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        // Create a viewer-only user (documents.view only, no documents.update).
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer2',
            'email' => 'viewer2@test.example',
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $viewer->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $postResponse = $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 8. FormRequest validation: precision regex rejections
    // -------------------------------------------------------------------------

    public function test_store_rejects_quantity_with_too_many_decimal_places(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $payload = $this->siPayload($po, $poLine, '10.0000', '50.000');
        $payload['lines'][0]['quantity'] = '10.00001'; // 5 decimals — exceeds scale 4

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.quantity']);
    }

    public function test_store_rejects_unit_price_with_too_many_decimal_places(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['lines'][0]['unit_price'] = '50.0001'; // 4 decimals — exceeds scale 3

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.unit_price']);
    }

    public function test_store_rejects_source_line_id_not_belonging_to_po(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        // Create a PO line that belongs to a DIFFERENT PO.
        $otherPo = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-OTHER-'.Str::random(6),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '500.000',
            'tax_amount' => '0.000',
            'total' => '500.000',
        ]);
        $otherLine = DocumentLine::create([
            'document_id' => $otherPo->id,
            'line_number' => 1,
            'description' => 'Other PO line',
            'quantity' => '10.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '10.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '50.000',
            'line_total' => '500.000',
            'allocated_costs' => '0.0000',
        ]);

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['lines'][0]['source_line_id'] = $otherLine->id; // belongs to OTHER PO

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
    }

    public function test_store_rejects_partner_mismatch_with_po(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        // Create a different supplier.
        $otherSupplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['partner_id'] = $otherSupplier->id; // does not match PO partner

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // BLOCKER 2 — currency must match PO currency
    // -------------------------------------------------------------------------

    public function test_store_rejects_currency_mismatch_with_po(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['currency'] = 'EUR'; // PO is TND

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['currency']);
    }

    // -------------------------------------------------------------------------
    // HIGH 2 — warn-mode price-variance: warning present in POST response
    // -------------------------------------------------------------------------

    public function test_post_warning_included_in_response_for_price_variance_under_warn(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // PO @ 100.000, receive 10 units.
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '10.0000',
            '100.000',
            'TND',
        );

        // Invoice @ 110.000 — 10% delta, well outside 2% / 1.000 TND tolerance.
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '10.0000', '110.000', '19.00'));
        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        // Policy is Warn (set up in setUp). Post should succeed with warning.
        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertOk();
        $data = $postResponse->json('data');
        $this->assertSame(DocumentStatus::Posted->value, $data['status']);
        // Warning must be present at the authoritative post-time status (HIGH 2 fix).
        $this->assertArrayHasKey('warning', $data, 'price-variance warning must be in response under Warn enforcement');
        $this->assertNotEmpty($data['warning']);
    }

    // -------------------------------------------------------------------------
    // HIGH 3 — block-mode price-variance: 422
    // -------------------------------------------------------------------------

    public function test_post_blocked_under_block_enforcement_with_price_variance(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '100.000');

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '10.0000',
            '100.000',
            'TND',
        );

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '10.0000', '110.000', '19.00'));
        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        // Switch to Block enforcement.
        ProcurementPolicy::where('company_id', $this->company->id)
            ->update(['match_enforcement' => MatchEnforcement::Block->value]);

        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertUnprocessable();

        // No JE, still Draft.
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $siId)->count());
        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertSame(DocumentStatus::Draft, $si->status);
    }

    // -------------------------------------------------------------------------
    // HIGH 4 — full real lifecycle: PO → GoodsReceipt → SI create → post
    //          Balanced §3 GL with 408, 4456 VAT, PurchaseStampDuty timbre, 401
    // -------------------------------------------------------------------------

    public function test_full_lifecycle_po_receive_invoice_post_balanced_je(): void
    {
        // Seed chart of accounts.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Ensure Tunisia country row exists (required by tax_configurations FK).
        Country::firstOrCreate(['code' => 'TN'], [
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        // Seed TaxConfiguration for NON_FISCAL documents (supplier invoices).
        TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => TaxType::Percentage,
            'name' => 'TVA 19% (achats)',
            'code' => 'TVA_19_PURCHASE',
            'percentage_rate' => '19.0000',
            'fixed_amount' => null,
            'applies_to' => TaxApplicationLevel::LineItems,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['NON_FISCAL'],
            'is_stamp_duty' => false,
            'is_recoverable' => true,
        ]);

        TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => TaxType::FixedAmount,
            'name' => 'Timbre Fiscal - Achat',
            'code' => 'STAMP_PURCHASE',
            'percentage_rate' => null,
            'fixed_amount' => '0.600',
            'applies_to' => TaxApplicationLevel::DocumentTotal,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['NON_FISCAL'],
            'is_stamp_duty' => true,
            'is_recoverable' => false,
        ]);

        // Goods receipt infrastructure: location + physical product.
        $location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-B3-01',
            'name' => 'B3 Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-B3-001',
            'name' => 'B3 Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => false,
            'cost_price' => '0.000',
        ]);

        // PO: qty=5 @ 10.000 TND.
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $location->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'PO-B3-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '50.000',
            'tax_amount' => '0.000',
            'total' => '50.000',
        ]);

        $poLine = DocumentLine::create([
            'document_id' => $po->id,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'line_number' => 1,
            'description' => $product->name,
            'quantity' => '5.0000',
            'quantity_delivered' => '0.0000',
            'quantity_received' => '0.0000',
            'quantity_invoiced' => '0.0000',
            'unit_price' => '10.000',
            'line_total' => '50.000',
            'allocated_costs' => '0.0000',
        ]);

        // Receive goods via GoodsReceiptService (fires GoodsReceived → 408 accrues).
        $po->load('lines');
        /** @var GoodsReceiptService $receiptService */
        $receiptService = app(GoodsReceiptService::class);
        $receiptService->receiveGoods($po, [$poLine->id => '5.0000']);

        // Refresh PO line so quantity_received is current.
        $poLine->refresh();

        // Assert 408 was accrued by the listener.
        $this->assertSame(1, JournalEntry::where('source_type', 'goods_receipt')
            ->where('company_id', $this->company->id)
            ->count(), 'GR-IR 408 entry must exist after receipt');

        // POST supplier invoice via HTTP (qty=5 @ 10.000, 19% VAT).
        // Expected: subtotal=50.000, VAT=9.500, stamp=0.600, total=60.100
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '5.0000', '10.000', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');
        $this->assertNotNull($siId);

        // Verify stamp_duty_amount was set by the service (BLOCKER 1 fix).
        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertNotNull($si);
        $this->assertSame('0.600', $si->stamp_duty_amount, 'stamp_duty_amount must be set by CreateSupplierInvoiceService');
        $this->assertSame('60.100', $si->total);

        // POST to post via HTTP.
        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertOk();
        $this->assertSame(DocumentStatus::Posted->value, $postResponse->json('data.status'));
        // No price variance, so no warning.
        $this->assertArrayNotHasKey('warning', $postResponse->json('data'));

        // Resolve GL accounts by purpose.
        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $vatAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $stampAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchaseStampDuty);
        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);

        // Load the clearing JE.
        $je = JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $siId)
            ->firstOrFail();
        $je->load('lines');

        // ── Assert debits == credits (balanced) ──────────────────────────────
        /** @var string $debitSum */
        $debitSum = '0.000';
        /** @var string $creditSum */
        $creditSum = '0.000';
        foreach ($je->lines as $jl) {
            $debitSum = bcadd($debitSum, $jl->debit, 3);
            $creditSum = bcadd($creditSum, $jl->credit, 3);
        }
        $this->assertSame('0.000', bcsub($debitSum, $creditSum, 3), 'Journal entry must balance: debits == credits');

        // ── Assert each §3 leg by SystemAccountPurpose ───────────────────────
        $legByAccount = fn (Account $a): ?JournalLine => $je->lines->firstWhere('account_id', $a->id);

        // Dr 408 cleared.
        $dr408 = $legByAccount($grirAccount);
        $this->assertNotNull($dr408, '408 (GR-IR) debit leg must exist');
        $this->assertSame('50.000', $dr408->debit);
        $this->assertNull($dr408->partner_id);

        // Dr 4456 VAT deductible.
        $drVat = $legByAccount($vatAccount);
        $this->assertNotNull($drVat, 'VatDeductible debit leg must exist');
        $this->assertSame('9.500', $drVat->debit);

        // Dr PurchaseStampDuty timbre.
        $drTimbre = $legByAccount($stampAccount);
        $this->assertNotNull($drTimbre, 'PurchaseStampDuty timbre leg must exist');
        $this->assertSame('0.600', $drTimbre->debit);

        // Cr 401 supplier payable, partner-tagged.
        $cr401 = $legByAccount($payableAccount);
        $this->assertNotNull($cr401, 'SupplierPayable credit leg must exist');
        $this->assertSame('60.100', $cr401->credit);
        $this->assertSame($this->supplier->id, $cr401->partner_id, '401 leg must be partner-tagged');

        // Hash chain integrity.
        /** @var GeneralLedgerHashService $hashService */
        $hashService = app(GeneralLedgerHashService::class);
        $this->assertTrue($hashService->verifyChain($this->company->id), 'Hash chain must verify after posting');
    }

    // -------------------------------------------------------------------------
    // HIGH 4 (supplemental) — over-invoice → 422, no JE created
    // -------------------------------------------------------------------------

    public function test_over_invoice_is_blocked_and_no_je_is_created(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // PO has qty=5 received, but SI invoices qty=10 (over-clear).
        [$po, $poLine] = $this->createPoWithReceipt('5.0000', '100.000');

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '5.0000',
            '100.000',
            'TND',
        );

        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '10.0000', '100.000', '19.00'));
        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertUnprocessable();
        $this->assertSame(0, JournalEntry::where('source_type', 'supplier_invoice')->where('source_id', $siId)->count());
        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertSame(DocumentStatus::Draft, $si->status);
    }

    // -------------------------------------------------------------------------
    // MEDIUM 3 — vat_rate precision ceiling
    // -------------------------------------------------------------------------

    public function test_store_rejects_vat_rate_with_too_many_decimal_places(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['lines'][0]['vat_rate'] = '19.001'; // 3 decimals — exceeds scale 2

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertUnprocessable();
        $this->assertJsonValidationErrors($response, ['lines.0.vat_rate']);
    }

    public function test_store_accepts_vat_rate_at_boundary(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $payload = $this->siPayload($po, $poLine, '5.0000', '50.000');
        $payload['lines'][0]['vat_rate'] = '19.00'; // exactly 2 decimals — valid

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $payload);

        $response->assertCreated();
    }

    // -------------------------------------------------------------------------
    // LOW — index filter coverage: match_status, date_from, date_to
    // -------------------------------------------------------------------------

    public function test_index_filters_by_match_status(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        // Create one SI (will be auto-matched as Matched or Unmatched based on receipt).
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '5.0000', '50.000'));

        // Filter by a status that should have no results.
        $none = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?match_status=exception');
        $none->assertOk();
        $this->assertCount(0, $none->json('data'));

        // Filter by the actual match_status of the created SI.
        $si = Document::where('type', DocumentType::SupplierInvoice)
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $match = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?match_status='.$si->match_status?->value);
        $match->assertOk();
        $this->assertCount(1, $match->json('data'));
    }

    public function test_index_filters_by_date_from(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));

        $tomorrow = now()->addDay()->toDateString();
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?date_from='.$tomorrow);
        $response->assertOk();
        $this->assertCount(0, $response->json('data'), 'date_from in future should return no results');

        $yesterday = now()->subDay()->toDateString();
        $response2 = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?date_from='.$yesterday);
        $response2->assertOk();
        $this->assertCount(1, $response2->json('data'), 'date_from in past should include todays invoice');
    }

    public function test_index_filters_by_date_to(): void
    {
        [$po, $poLine] = $this->createPoWithReceipt('10.0000', '50.000');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '3.0000', '50.000'));

        $yesterday = now()->subDay()->toDateString();
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?date_to='.$yesterday);
        $response->assertOk();
        $this->assertCount(0, $response->json('data'), 'date_to in past should return no results');

        $tomorrow = now()->addDay()->toDateString();
        $response2 = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/supplier-invoices?date_to='.$tomorrow);
        $response2->assertOk();
        $this->assertCount(1, $response2->json('data'), 'date_to in future should include today\'s invoice');
    }

    // -------------------------------------------------------------------------
    // HIGH 1 — precision regression: bcround not bcformat/truncation for invoice legs
    //
    // Values: qty=1.0000, unit_price=10.003, vat_rate=19%
    //   subtotal (exact) = 10.003
    //   VAT (7dp)        = 10.003 × 0.19 = 1.9005700
    //   bcformat/truncate → 1.900   (drops the .5 at the 4th decimal place)
    //   bcround half-up  → 1.901   (4th decimal = 5 → rounds up)
    //
    // This test MUST FAIL (RED) against the bcformat code and PASS (GREEN) after
    // the bcround fix lands.
    // -------------------------------------------------------------------------

    public function test_precision_regression_bcround_not_truncation_for_vat_leg(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        Country::firstOrCreate(['code' => 'TN'], [
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        // Stamp-duty TaxConfiguration so stamp_duty_amount is resolved.
        TaxConfiguration::create([
            'country_code' => 'TN',
            'tax_type' => TaxType::FixedAmount,
            'name' => 'Timbre Fiscal - Achat (precision test)',
            'code' => 'STAMP_PURCHASE_PREC',
            'percentage_rate' => null,
            'fixed_amount' => '0.600',
            'applies_to' => TaxApplicationLevel::DocumentTotal,
            'is_default' => false,
            'is_active' => true,
            'sequence_order' => 99,
            'stacks_on' => 'SUBTOTAL',
            'applicable_document_types' => ['NON_FISCAL'],
            'is_stamp_duty' => true,
            'is_recoverable' => false,
        ]);

        // PO: qty=1 @ 10.003 TND (matches invoice price → no price variance).
        [$po, $poLine] = $this->createPoWithReceipt('1.0000', '10.003');

        // Accrue the GR-IR 408 entry (GoodsReceiptService path replaced by direct GL call
        // so this test stays focused on the precision boundary).
        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '1.0000',
            '10.003',
            'TND',
        );

        // Create supplier invoice via HTTP.
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '1.0000', '10.003', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        /** @var Document $si */
        $si = Document::find($siId);
        $this->assertNotNull($si);
        $si->load('lines');
        $siLine = $si->lines->first();
        $this->assertNotNull($siLine);

        // ── PRECISION ASSERTION: bcround gives 1.901, bcformat/truncation gives 1.900 ──
        // 10.003 × 0.19 = 1.9005700 at 7dp; the 4th decimal is 5 → half-up rounds UP.
        $this->assertSame('1.901', $siLine->recoverable_tax_amount, 'VAT must be half-up rounded (bcround), not truncated (bcformat): 1.9005700 → 1.901');
        $this->assertSame('1.901', $siLine->tax_amount, 'line tax_amount must match bcround result');

        // Total = subtotal(10.003) + VAT(1.901) + stamp(0.600) = 12.504
        $this->assertSame('12.504', $si->total, 'Document total must reflect bcround VAT');

        // Post and assert the 4456 GL leg carries the rounded amount.
        $postResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $postResponse->assertOk();

        $vatAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $je = JournalEntry::where('source_type', 'supplier_invoice')
            ->where('source_id', $siId)
            ->firstOrFail();
        $je->load('lines');

        $drVat = $je->lines->firstWhere('account_id', $vatAccount->id);
        $this->assertNotNull($drVat, '4456 VatDeductible leg must exist');
        $this->assertSame('1.901', $drVat->debit, '4456 GL leg must carry bcround VAT (1.901), not truncated (1.900)');
    }

    // -------------------------------------------------------------------------
    // HIGH 2 — idempotent POST .../post on retry: both 200, one JE, qty_invoiced++ once
    // -------------------------------------------------------------------------

    public function test_post_endpoint_is_idempotent_on_retry(): void
    {
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        [$po, $poLine] = $this->createPoWithReceipt('5.0000', '100.000');

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '5.0000',
            '100.000',
            'TND',
        );

        // Create SI.
        $storeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/supplier-invoices', $this->siPayload($po, $poLine, '5.0000', '100.000', '19.00'));

        $storeResponse->assertCreated();
        $siId = $storeResponse->json('data.id');

        // First POST → 200, SI Posted.
        $first = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $first->assertOk();
        $this->assertSame(DocumentStatus::Posted->value, $first->json('data.status'));

        // Second POST (retry/double-click) → MUST also return 200 (not 422).
        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/supplier-invoices/{$siId}/post");

        $second->assertOk('Second POST to /post must be idempotent and return 200, not 422');
        $this->assertSame(DocumentStatus::Posted->value, $second->json('data.status'));

        // Exactly ONE supplier_invoice JE — no duplicate GL entry.
        $jeCount = JournalEntry::query()
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $siId)
            ->count();
        $this->assertSame(1, $jeCount, 'Exactly one JE must exist after two POST calls');

        // PO line quantity_invoiced incremented exactly once.
        $poLine->refresh();
        $this->assertSame('5.0000', $poLine->quantity_invoiced, 'quantity_invoiced must be incremented exactly once');
    }

    private function assertApiValidationEnvelope(TestResponse $response): void
    {
        $response->assertUnprocessable()
            ->assertJsonStructure([
                'error' => [
                    'errors',
                ],
            ]);
    }
}
