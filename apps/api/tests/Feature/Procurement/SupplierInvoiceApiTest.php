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
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
}
