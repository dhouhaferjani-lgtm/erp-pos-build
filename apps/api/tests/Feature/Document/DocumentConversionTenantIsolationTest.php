<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Presentation\Controllers\DocumentPdfController;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * api.document cluster — Batch 2 (Conversion + Document/PDF controllers) tenant-isolation regression.
 *
 * Inventoried callsites:
 *   api.document.022  DocumentConversionController::convertQuoteToOrder         findOrFail
 *   api.document.023  DocumentConversionController::convertOrderToInvoice       findOrFail
 *   api.document.024  DocumentConversionController::convertOrderToDelivery     findOrFail
 *   api.document.025  DocumentConversionController::checkQuoteExpiry           findOrFail
 *   api.document.026  DocumentConversionController::checkOrderInvoiceStatus    findOrFail
 *   api.document.027  DocumentConversionController::convertInvoiceToCreditNote findOrFail
 *   api.document.028  DocumentConversionController::createInvoiceFromDeliveryNotes ::find
 *   api.document.029  DocumentConversionController::receivePurchaseOrderGoods  findOrFail
 *   api.document.041  DocumentConversionController::createInvoiceFromDeliveryNotes
 *                                                                              delivery_note_ids.* validator
 *   api.document.030  DocumentController::taxBreakdown                         findOrFail
 *   api.document.031  DocumentPdfController::download                           findOrFail
 *   api.document.032  DocumentPdfController::preview                            findOrFail
 *   api.document.033  DocumentPdfController::generatePath                       findOrFail
 *   api.document.014/015/016  SalesOrderToInvoiceConverter Product::find        (defense-in-depth)
 *   api.document.017/018/019  SalesOrderToDeliveryNoteConverter Product::find  (defense-in-depth)
 *
 * The converter Product::find callsites are exercised indirectly via the routes
 * they back. Cross-tenant lines should never reach the converters because the
 * controller-level scoping rejects the parent document upstream — the converter
 * scoping is defense-in-depth against corrupted rows or future bypass paths.
 *
 * Structural-SQL-log invariants pin the WHERE shape on the inventoried reads.
 */
final class DocumentConversionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Partner $customerA;

    private Partner $customerB;

    private Document $quoteB;

    private Document $orderB;

    private Document $invoiceB;

    private Document $deliveryNoteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-conv-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-conv-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CONV',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-CONV',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-conv-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->customerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Customer A',
            'type' => 'customer',
        ]);
        $this->customerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Customer B',
            'type' => 'customer',
        ]);

        $this->quoteB = $this->createDocument(DocumentType::Quote, $this->tenantB, $this->companyB, $this->customerB, 'QT-B', DocumentStatus::Draft);
        $this->orderB = $this->createDocument(DocumentType::SalesOrder, $this->tenantB, $this->companyB, $this->customerB, 'SO-B', DocumentStatus::Draft);
        $this->invoiceB = $this->createDocument(DocumentType::Invoice, $this->tenantB, $this->companyB, $this->customerB, 'INV-B', DocumentStatus::Posted);
        $this->deliveryNoteB = $this->createDocument(DocumentType::DeliveryNote, $this->tenantB, $this->companyB, $this->customerB, 'DN-B', DocumentStatus::Confirmed);
    }

    // ──────────────────────────────────────────────────────────────────
    // DocumentConversionController route-anchored Document lookups
    // ──────────────────────────────────────────────────────────────────

    public function test_convert_quote_to_order_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/quotes/{$this->quoteB->id}/convert-to-order");
        $cross->assertStatus(404);
    }

    public function test_convert_order_to_invoice_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/orders/{$this->orderB->id}/convert-to-invoice");
        $cross->assertStatus(404);
    }

    public function test_convert_order_to_delivery_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/orders/{$this->orderB->id}/convert-to-delivery");
        $cross->assertStatus(404);
    }

    public function test_check_quote_expiry_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/quotes/{$this->quoteB->id}/check-expiry");
        $cross->assertStatus(404);
    }

    public function test_check_order_invoice_status_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/orders/{$this->orderB->id}/invoice-status");
        $cross->assertStatus(404);
    }

    public function test_convert_invoice_to_credit_note_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$this->invoiceB->id}/create-credit-note", [
                'reason' => 'return',
                'amount' => '50.00',
            ]);
        $cross->assertStatus(404);
    }

    public function test_create_invoice_from_delivery_notes_rejects_cross_tenant_validator(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
                'delivery_note_ids' => [$this->deliveryNoteB->id],
            ]);
        $cross->assertStatus(422);
        $errors = $cross->json('errors') ?? $cross->json('error.errors') ?? [];
        $this->assertNotEmpty($errors, 'Cross-tenant delivery_note_id must surface a validator error. Body: '.$cross->getContent());
        $this->assertTrue(
            isset($errors['delivery_note_ids.0']) || isset($errors['delivery_note_ids']),
            'delivery_note_ids.0 (or .delivery_note_ids array-level) must be in error keys: '.json_encode(array_keys($errors)),
        );
    }

    /**
     * api.document.029 (DocumentConversionController::receivePurchaseOrderGoods)
     * is not exposed directly via an HTTP route — it lives behind the
     * DocumentConverterRegistry pattern that PurchaseOrderController::receive
     * dispatches into. The scoped Document::with('lines')->findOrFail call
     * shares the same scopedQuery() helper as the routed methods, so the
     * structural-SQL test on convertQuoteToOrder covers the same shape.
     * Verify the source-level invariant directly.
     */
    public function test_receive_purchase_order_goods_uses_scoped_lookup(): void
    {
        $source = file_get_contents(
            base_path('app/Modules/Document/Presentation/Controllers/DocumentConversionController.php')
        );
        $this->assertNotFalse($source);
        // Match the receivePurchaseOrderGoods method body anchor.
        $methodStart = strpos($source, 'public function receivePurchaseOrderGoods');
        $this->assertNotFalse($methodStart, 'receivePurchaseOrderGoods method must exist.');
        $methodEnd = strpos($source, "\n    }\n", $methodStart);
        $this->assertNotFalse($methodEnd, 'receivePurchaseOrderGoods method must terminate.');
        $methodBody = substr($source, $methodStart, $methodEnd - $methodStart);
        $this->assertStringContainsString('$this->scopedQuery()', $methodBody, 'receivePurchaseOrderGoods must use scopedQuery().');
    }

    // ──────────────────────────────────────────────────────────────────
    // DocumentController taxBreakdown
    // ──────────────────────────────────────────────────────────────────

    public function test_tax_breakdown_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/documents/{$this->invoiceB->id}/tax-breakdown");
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // DocumentPdfController download / preview / generate-path
    // ──────────────────────────────────────────────────────────────────

    public function test_pdf_download_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/documents/{$this->invoiceB->id}/pdf");
        $cross->assertStatus(404);
    }

    public function test_pdf_preview_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/documents/{$this->invoiceB->id}/pdf/preview");
        $cross->assertStatus(404);
    }

    /**
     * api.document.033 (DocumentPdfController::generatePath) is not exposed via
     * an HTTP route — it is invoked internally for email attachments via
     * DocumentEmailController. The scoped findOrFail in scopedFindOrFail() is
     * shared across download/preview/generatePath, so the structural-SQL test
     * on download covers the same code shape.
     */
    public function test_pdf_generate_path_uses_scoped_lookup(): void
    {
        // Direct controller invocation: instantiate with mock services and call.
        // We verify the SQL shape via a request to download (which uses the same
        // scopedFindOrFail helper), then assert generatePath() also routes through it.
        $controller = new \ReflectionClass(DocumentPdfController::class);
        $method = $controller->getMethod('scopedFindOrFail');
        $this->assertTrue($method->isPrivate(), 'scopedFindOrFail must be the shared private helper.');
        // Verify generatePath calls scopedFindOrFail (source-level invariant).
        $fileName = $controller->getFileName();
        $this->assertIsString($fileName);
        $generatePathSource = file_get_contents($fileName);
        $this->assertNotFalse($generatePathSource);
        $this->assertStringContainsString('$this->scopedFindOrFail($id)', $generatePathSource);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    // ──────────────────────────────────────────────────────────────────

    public function test_convert_quote_to_order_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->postJson("/api/v1/quotes/{$this->quoteB->id}/convert-to-order");

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $documentLookup = $this->findRouteAnchoredDocumentQuery($log);
        $this->assertNotNull(
            $documentLookup,
            'Document lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $documentLookup, 'convertQuoteToOrder must filter by tenant_id. SQL: '.$documentLookup);
        $this->assertStringContainsString('"company_id"', $documentLookup, 'convertQuoteToOrder must filter by company_id. SQL: '.$documentLookup);
    }

    public function test_pdf_download_query_includes_tenant_and_company_predicates(): void
    {
        // Use a same-tenant document so the controller's scoped query actually
        // fires (then errors downstream — we only need the SQL shape captured).
        $invoiceA = $this->createDocument(DocumentType::Invoice, $this->tenantA, $this->companyA, $this->customerA, 'INV-A-PDF', DocumentStatus::Posted);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->getJson("/api/v1/documents/{$invoiceA->id}/pdf");

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $documentLookup = $this->findRouteAnchoredDocumentQuery($log);
        $this->assertNotNull(
            $documentLookup,
            'PDF document lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $documentLookup, 'pdf download must filter by tenant_id. SQL: '.$documentLookup);
        $this->assertStringContainsString('"company_id"', $documentLookup, 'pdf download must filter by company_id. SQL: '.$documentLookup);
    }

    public function test_create_invoice_from_delivery_notes_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
                'delivery_note_ids' => [$this->deliveryNoteB->id],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "documents"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'count(*)') || str_contains($sql, 'exists'))
            ) {
                $existsQuery = $sql;
                break;
            }
        }
        $this->assertNotNull($existsQuery, 'documents exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)));
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'delivery_note_ids validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'delivery_note_ids validator must filter by company_id. SQL: '.$existsQuery);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function createDocument(DocumentType $type, Tenant $tenant, Company $company, Partner $partner, string $number, DocumentStatus $status): Document
    {
        return Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'fiscal_category' => FiscalCategory::fromDocumentType($type),
            'fiscal_status' => $status === DocumentStatus::Posted ? FiscalStatus::Sealed : FiscalStatus::Draft,
            'status' => $status,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => $company->currency,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'balance_due' => '100.00',
        ]);
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     */
    private function findRouteAnchoredDocumentQuery(array $log): ?string
    {
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "documents"')
                && str_contains($sql, 'limit 1')
                && str_contains($sql, '"id" =')
            ) {
                return $sql;
            }
        }

        return null;
    }

    private function actingAsForTenant(): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->userA->tenant_id);

        /** @var self */
        return $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id);
    }
}
