<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * api.document cluster — Batch 1 (CreditNote slice) tenant-isolation regression coverage.
 *
 * Inventoried callsites:
 *   api.document.001  CreditNoteController.php:139 bare exists:documents,id   (source_invoice_id validator)
 *   api.document.002  CreditNoteController.php:152 bare exists:partners,id    (partner_id validator)
 *   api.document.003  CreditNoteController.php:154 bare exists:products,id    (lines.*.product_id validator)
 *   api.document.004  DocumentData.php:134         Document::find(source_document_id) defense-in-depth
 *   api.document.005  CreditNoteService.php:37     Document::with('lines')->lockForUpdate()->findOrFail()
 *   api.document.006  CreditNoteService.php:128    Document::with('lines')->lockForUpdate()->findOrFail()
 *   api.document.007  CreditNoteService.php:283    Partner::lockForUpdate()->findOrFail()
 *   api.document.008  CreditNoteService.php:424    Document::lockForUpdate()->findOrFail() (allocateCreditNote)
 *
 * Each test is two-tier: validator path (Presentation) ensures cross-tenant ids are rejected
 * at HTTP boundary; service path ensures direct service callers (tests, jobs, listeners) cannot
 * pierce the boundary even when the validator is bypassed. Structural-SQL-log invariants pin
 * the WHERE shape on the inventoried lockForUpdate->findOrFail and exists-validator queries.
 */
final class CreditNoteTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Partner $customerA;

    private Partner $customerB;

    private Product $productB;

    private Document $invoiceA;

    private Document $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-cn-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-cn-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CN',
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
            'tax_id' => 'TAX-B-CN',
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
            'email' => 'alice-cn-iso@example.com',
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

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'sku' => 'SKU-B-CN',
            'name' => 'Product B',
        ]);

        $this->invoiceA = $this->createPostedInvoice($this->tenantA, $this->companyA, $this->customerA, '1000.00', 'INV-A-CN');
        $this->invoiceB = $this->createPostedInvoice($this->tenantB, $this->companyB, $this->customerB, '500.00', 'INV-B-CN');
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.001 — store: source_invoice_id validator
    // ──────────────────────────────────────────────────────────────────

    public function test_store_rejects_cross_tenant_source_invoice_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $this->invoiceB->id,
                'amount' => '100.00',
                'reason' => CreditNoteReason::RETURN->value,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('source_invoice_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.002 — store standalone: partner_id validator
    // ──────────────────────────────────────────────────────────────────

    public function test_store_standalone_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/credit-notes', [
                'partner_id' => $this->customerB->id,
                'lines' => [[
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => '50.00',
                    'tax_rate' => 19,
                ]],
                'reason' => CreditNoteReason::RETURN->value,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.003 — store standalone: lines.*.product_id validator
    // ──────────────────────────────────────────────────────────────────

    public function test_store_standalone_rejects_cross_tenant_line_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/credit-notes', [
                'partner_id' => $this->customerA->id,
                'lines' => [[
                    'product_id' => $this->productB->id,
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => '50.00',
                    'tax_rate' => 19,
                ]],
                'reason' => CreditNoteReason::RETURN->value,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('lines.0.product_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.004 — DocumentData::fromModel source_document lookup
    // (defense-in-depth: corrupted source_document_id pointing across
    //  tenants must NOT surface the foreign document's number/type.)
    // ──────────────────────────────────────────────────────────────────

    public function test_document_data_does_not_leak_cross_tenant_source_document(): void
    {
        // Construct a tenant-A credit note with a source_document_id pointing
        // at tenant-B's invoice. This bypasses the now-scoped service paths
        // by writing directly through Eloquent — simulating a corrupted row
        // (or a future bug elsewhere). The DTO must refuse to surface the
        // foreign source_document's number/type.
        $creditNoteA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'type' => DocumentType::CreditNote,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
            'status' => DocumentStatus::Draft,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'CN-A-LEAK',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'source_document_id' => $this->invoiceB->id,
        ]);

        $data = DocumentData::fromModel($creditNoteA);

        $this->assertSame($this->invoiceB->id, $data->source_document_id);
        $this->assertNull(
            $data->source_document_number,
            'DocumentData must not expose cross-tenant source_document_number.',
        );
        $this->assertNull(
            $data->source_document_type,
            'DocumentData must not expose cross-tenant source_document_type.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.005 — CreditNoteService::createCreditNote sourceInvoiceId scope
    // ──────────────────────────────────────────────────────────────────

    public function test_create_credit_note_service_rejects_cross_tenant_source_invoice_id(): void
    {
        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);
        /** @var CompanyContext $companyContext */
        $companyContext = app(CompanyContext::class);
        $companyContext->setCompanyId($this->companyA->id);

        $this->expectException(ModelNotFoundException::class);

        $service->createCreditNote(
            sourceInvoiceId: $this->invoiceB->id,
            amount: '100.00',
            reason: CreditNoteReason::RETURN,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.006 — CreditNoteService::createLineBasedCreditNote scope
    // ──────────────────────────────────────────────────────────────────

    public function test_create_line_based_credit_note_service_rejects_cross_tenant_source_invoice_id(): void
    {
        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);
        /** @var CompanyContext $companyContext */
        $companyContext = app(CompanyContext::class);
        $companyContext->setCompanyId($this->companyA->id);

        $this->expectException(ModelNotFoundException::class);

        $service->createLineBasedCreditNote(
            sourceInvoiceId: $this->invoiceB->id,
            lines: [['line_id' => 'irrelevant', 'quantity' => 1]],
            reason: CreditNoteReason::RETURN,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.007 — CreditNoteService::createStandaloneCreditNote scope
    // ──────────────────────────────────────────────────────────────────

    public function test_create_standalone_credit_note_service_rejects_cross_tenant_partner_id(): void
    {
        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);
        /** @var CompanyContext $companyContext */
        $companyContext = app(CompanyContext::class);
        $companyContext->setCompanyId($this->companyA->id);

        $this->expectException(ModelNotFoundException::class);

        $service->createStandaloneCreditNote(
            partnerId: $this->customerB->id,
            lines: [[
                'description' => 'Compensation',
                'quantity' => 1,
                'unit_price' => '50.00',
                'tax_rate' => 19,
            ]],
            reason: CreditNoteReason::RETURN,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.008 — CreditNoteService::allocateCreditNote scope
    //
    // The credit note is owned by tenant A but its source_document_id is
    // tenant B's invoice (corrupted-row scenario). allocateCreditNote
    // resolves the source invoice via Document::lockForUpdate->findOrFail
    // — must scope by the credit note's tenant + company, refusing the
    // foreign invoice instead of allocating across tenants.
    // ──────────────────────────────────────────────────────────────────

    public function test_allocate_credit_note_service_rejects_cross_tenant_source_invoice(): void
    {
        $creditNoteA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'document_number' => 'CN-A-ALLOC',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '50.00',
            'tax_amount' => '0.00',
            'total' => '50.00',
            'source_document_id' => $this->invoiceB->id,
        ]);

        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);

        $this->expectException(ModelNotFoundException::class);

        $service->allocateCreditNote($creditNoteA);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    //
    // Pin the WHERE shape of the inventoried reads. A future regression
    // that drops tenant_id or company_id from the chain fails loudly here
    // even if behavior happens to remain correct under UUID uniqueness.
    // ──────────────────────────────────────────────────────────────────

    public function test_store_source_invoice_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $this->invoiceA->id,
                'amount' => '50.00',
                'reason' => CreditNoteReason::RETURN->value,
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = $this->findExistsQuery($log, 'documents');
        $this->assertNotNull(
            $existsQuery,
            'documents exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'source_invoice_id validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'source_invoice_id validator must filter by company_id. SQL: '.$existsQuery);
    }

    public function test_store_standalone_partner_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/credit-notes', [
                'partner_id' => $this->customerA->id,
                'lines' => [[
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => '50.00',
                    'tax_rate' => 19,
                ]],
                'reason' => CreditNoteReason::RETURN->value,
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = $this->findExistsQuery($log, 'partners');
        $this->assertNotNull(
            $existsQuery,
            'partners exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'standalone partner_id validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'standalone partner_id validator must filter by company_id. SQL: '.$existsQuery);
    }

    public function test_create_credit_note_service_query_includes_tenant_and_company_predicates(): void
    {
        /** @var CreditNoteService $service */
        $service = app(CreditNoteService::class);
        /** @var CompanyContext $companyContext */
        $companyContext = app(CompanyContext::class);
        $companyContext->setCompanyId($this->companyA->id);

        DB::enableQueryLog();

        $caught = false;
        try {
            $service->createCreditNote(
                sourceInvoiceId: $this->invoiceB->id,
                amount: '100.00',
                reason: CreditNoteReason::RETURN,
            );
        } catch (\Throwable $e) {
            $caught = $e instanceof ModelNotFoundException;
        }
        $this->assertTrue($caught, 'Expected ModelNotFoundException on cross-tenant sourceInvoiceId.');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $documentLookup = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, 'from "documents"') && str_contains($sql, '"id" =') && str_contains($sql, 'limit 1')) {
                $documentLookup = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $documentLookup,
            'documents lockForUpdate->findOrFail query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $documentLookup, 'createCreditNote service-tier read must filter by tenant_id. SQL: '.$documentLookup);
        $this->assertStringContainsString('"company_id"', $documentLookup, 'createCreditNote service-tier read must filter by company_id. SQL: '.$documentLookup);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function createPostedInvoice(Tenant $tenant, Company $company, Partner $partner, string $total, string $number): Document
    {
        return Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => $company->currency,
            'subtotal' => $total,
            'tax_amount' => '0.00',
            'total' => $total,
            'balance_due' => $total,
        ]);
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     */
    private function findExistsQuery(array $log, string $table): ?string
    {
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "'.$table.'"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'count(*)') || str_contains($sql, 'exists'))
            ) {
                return $sql;
            }
        }

        return null;
    }

    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
