<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * api.document cluster — Batch 3 (Refund + residual services) tenant-isolation regression.
 *
 * Inventoried callsites:
 *   api.document.009  AgedReceivablesService:179        Partner findOrFail
 *   api.document.010  DocumentPostingService:293        Product find
 *   api.document.011  DraftPersistenceService:59        Document find  (saveDraft)
 *   api.document.012  DraftPersistenceService:199       Product find   (addLine)
 *   api.document.013  DraftPersistenceService:202       Service find   (addLine)
 *   api.document.020  ReturnNoteService:169             Location findOrFail
 *   api.document.021  DeliveryNoteService:169           Document find
 *   api.document.034-040  RefundController findOrFail (7 endpoints)
 *                                                       cancelInvoice / cancelCreditNote /
 *                                                       createFullCreditNote / createPartialCreditNote /
 *                                                       checkCancellable / checkCreditable / getCreditNoteSummary
 *   api.document.042  RefundController:114              line_items.*.product_id validator
 *
 * Several Domain services are not exposed via HTTP routes — their fix is
 * verified at the source level (string-search the production code) plus the
 * structural-SQL-log invariant on the routed surfaces. RefundController
 * routes drive the SQL test to confirm the scoping shape.
 */
final class RefundResidualTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Partner $customerA;

    private Document $invoiceB;

    private Document $creditNoteB;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-refund-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-refund-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-RF',
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
            'tax_id' => 'TAX-B-RF',
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
            'email' => 'alice-refund-iso@example.com',
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

        // Grant the permissions whose routes we test that admin doesn't include
        // by default (credit-notes.cancel and invoices.cancel).
        foreach (['credit-notes.cancel', 'invoices.cancel'] as $perm) {
            $permission = Permission::firstOrCreate(
                ['name' => $perm, 'guard_name' => 'sanctum']
            );
            $this->userA->givePermissionTo($permission);
        }

        $this->customerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Customer A',
            'type' => 'customer',
        ]);
        $customerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Customer B',
            'type' => 'customer',
        ]);

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Product B',
        ]);

        $this->invoiceB = Document::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $customerB->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-B-RF',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);
        $this->creditNoteB = Document::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $customerB->id,
            'type' => DocumentType::CreditNote,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'status' => DocumentStatus::Posted,
            'document_number' => 'CN-B-RF',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // RefundController route-anchored Document lookups (api.document.034-040)
    // ──────────────────────────────────────────────────────────────────

    public function test_cancel_invoice_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$this->invoiceB->id}/cancel", ['reason' => 'test']);
        $cross->assertStatus(404);
    }

    public function test_cancel_credit_note_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/credit-notes/{$this->creditNoteB->id}/cancel", ['reason' => 'test']);
        $cross->assertStatus(404);
    }

    public function test_create_full_credit_note_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$this->invoiceB->id}/credit-full", ['reason' => 'test']);
        $cross->assertStatus(404);
    }

    public function test_create_partial_credit_note_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$this->invoiceB->id}/credit-partial", [
                'reason' => 'test',
                'line_items' => [[
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'subtotal' => 50,
                    'total' => 50,
                ]],
            ]);
        $cross->assertStatus(404);
    }

    public function test_check_cancellable_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/invoices/{$this->invoiceB->id}/can-cancel");
        $cross->assertStatus(404);
    }

    public function test_check_creditable_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/invoices/{$this->invoiceB->id}/can-credit");
        $cross->assertStatus(404);
    }

    public function test_get_credit_note_summary_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant()
            ->getJson("/api/v1/invoices/{$this->invoiceB->id}/credit-summary");
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.042 — line_items.*.product_id validator (refund partial)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_partial_credit_note_rejects_cross_tenant_product_id(): void
    {
        // Use a same-tenant invoice so the controller-level scope passes,
        // exposing the validator path for cross-tenant product_id.
        $invoiceA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-A-RF',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        $cross = $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$invoiceA->id}/credit-partial", [
                'reason' => 'test',
                'line_items' => [[
                    'product_id' => $this->productB->id,
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'subtotal' => 50,
                    'total' => 50,
                ]],
            ]);
        $cross->assertStatus(422);
        $errors = $cross->json('errors') ?? $cross->json('error.errors') ?? [];
        $this->assertNotEmpty($errors);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    // ──────────────────────────────────────────────────────────────────

    public function test_cancel_invoice_query_includes_tenant_and_company_predicates(): void
    {
        $invoiceA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-A-CANCEL-SQL',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$invoiceA->id}/cancel", ['reason' => 'test']);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $documentLookup = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "documents"')
                && str_contains($sql, '"id" =')
                && str_contains($sql, 'limit 1')
            ) {
                $documentLookup = $sql;
                break;
            }
        }

        $this->assertNotNull($documentLookup, 'Document lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)));
        $this->assertStringContainsString('"tenant_id"', $documentLookup, 'cancelInvoice must filter by tenant_id. SQL: '.$documentLookup);
        $this->assertStringContainsString('"company_id"', $documentLookup, 'cancelInvoice must filter by company_id. SQL: '.$documentLookup);
    }

    public function test_create_partial_credit_note_validator_query_includes_tenant_and_company_predicates(): void
    {
        $invoiceA = Document::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->customerA->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', uniqid('seal-', true)),
            'chain_sequence' => 1,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-A-PARTIAL-SQL',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->postJson("/api/v1/invoices/{$invoiceA->id}/credit-partial", [
                'reason' => 'test',
                'line_items' => [[
                    'product_id' => $this->productB->id,
                    'description' => 'Compensation',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'subtotal' => 50,
                    'total' => 50,
                ]],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'count(*)') || str_contains($sql, 'exists'))
            ) {
                $existsQuery = $sql;
                break;
            }
        }

        $this->assertNotNull($existsQuery, 'products exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)));
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'product_id validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'product_id validator must filter by company_id. SQL: '.$existsQuery);
    }

    // ──────────────────────────────────────────────────────────────────
    // Source-level invariants for non-routed Domain services
    // (api.document.009, .010, .011, .012, .013, .020, .021)
    // ──────────────────────────────────────────────────────────────────

    public function test_aged_receivables_service_uses_company_scoped_partner_lookup(): void
    {
        $source = $this->readSource('app/Modules/Document/Application/Services/AgedReceivablesService.php');
        $this->assertStringContainsString('api.document.009', $source);
        $this->assertMatchesRegularExpression('/Partner::query\(\)\s*->where\([\'"]company_id[\'"]/', $source);
    }

    public function test_document_posting_service_uses_scoped_product_lookup(): void
    {
        $source = $this->readSource('app/Modules/Document/Domain/Services/DocumentPostingService.php');
        $this->assertStringContainsString('api.document.010', $source);

        // DPA Wave 3 T4 / D-19: the lookup itself moved into the ONE physical
        // predicate, so the invariant is now enforced in TWO places and this
        // guard follows it rather than weakening. DocumentPostingService must
        // still pass BOTH scope ids…
        $this->assertMatchesRegularExpression(
            '/PhysicalLinePredicate::forLine\(\s*\$line,\s*\$invoice->tenant_id,\s*\$invoice->company_id\s*\)/',
            $source,
            'validateDeliveryCompliance must hand the predicate BOTH tenant_id and company_id (api.document.010).',
        );

        // …and the predicate must still scope on BOTH.
        // Opus Finding D: require BOTH tenant_id AND company_id, not just tenant_id.
        $predicate = $this->readSource('app/Modules/Inventory/Domain/PhysicalLinePredicate.php');
        $this->assertMatchesRegularExpression(
            '/Product::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $predicate,
            'PhysicalLinePredicate must scope the product lookup by tenant_id AND company_id.',
        );
    }

    public function test_draft_persistence_service_uses_scoped_lookups(): void
    {
        // Codex round-1 Finding 2: file-wide regex is insufficient when the
        // same model class is queried from multiple methods. Anchor each check
        // to the method body so a regression in addLine alone (or
        // addLinesBatch alone) cannot be masked by the other path keeping the
        // predicates intact.
        $source = $this->readSource('app/Modules/Document/Domain/Services/DraftPersistenceService.php');
        $this->assertStringContainsString('api.document.011', $source);
        $this->assertStringContainsString('api.document.012', $source);
        $this->assertStringContainsString('api.document.013', $source);
        $this->assertStringContainsString('api.document.043', $source);
        $this->assertStringContainsString('api.document.044', $source);

        $saveDraft = $this->extractMethodBody($source, 'public function saveDraft');
        $this->assertMatchesRegularExpression(
            '/Document::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $saveDraft,
            'saveDraft Document::query() must scope by both tenant_id and company_id (api.document.011).',
        );

        $addLine = $this->extractMethodBody($source, 'private function addLine');
        $this->assertMatchesRegularExpression(
            '/Product::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $addLine,
            'addLine Product::query() must scope by both tenant_id and company_id (api.document.012).',
        );
        $this->assertMatchesRegularExpression(
            '/Service::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $addLine,
            'addLine Service::query() must scope by both tenant_id and company_id (api.document.013).',
        );

        $addLinesBatch = $this->extractMethodBody($source, 'private function addLinesBatch');
        $this->assertMatchesRegularExpression(
            '/Product::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $addLinesBatch,
            'addLinesBatch Product::query() must scope by both tenant_id and company_id (api.document.043).',
        );
        $this->assertMatchesRegularExpression(
            '/Service::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/',
            $addLinesBatch,
            'addLinesBatch Service::query() must scope by both tenant_id and company_id (api.document.044).',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.document.043/044 — DraftPersistenceService::addLinesBatch leak
    // (Opus round-1 Finding A): the /auto-save route accepts unrestricted
    // request input with no validator, and the batch path uses unscoped
    // Product::whereIn / Service::whereIn that loaded foreign tenants'
    // products and snapshotted their names into local draft lines.
    // ──────────────────────────────────────────────────────────────────

    public function test_auto_save_batch_lines_does_not_leak_cross_tenant_product_name(): void
    {
        // Seed a tenant-B product with a distinctive name so the leak is
        // visually unambiguous in the assertion.
        $productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'TenantBSecretProductName',
        ]);

        // POST ≥2 lines to hit the batch path (saveDraft dispatches to
        // addLinesBatch when lines count > 1).
        $response = $this->actingAsForTenant()
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customerA->id,
                'lines' => [
                    [
                        'product_id' => $productB->id,
                        'quantity' => 1,
                        'unit_price' => 50,
                    ],
                    [
                        'product_id' => $productB->id,
                        'quantity' => 1,
                        'unit_price' => 60,
                    ],
                ],
            ]);

        $response->assertStatus(200);

        // Critical post-condition: no DocumentLine in tenant A may carry the
        // foreign product's name in description / designation_default_snapshot.
        $linesWithSecret = DocumentLine::query()
            ->where(function ($q) {
                $q->where('description', 'TenantBSecretProductName')
                    ->orWhere('designation_default_snapshot', 'TenantBSecretProductName');
            })
            ->get();

        $this->assertCount(
            0,
            $linesWithSecret,
            'addLinesBatch leaked tenant-B product name into a tenant-A draft. Found '
            .$linesWithSecret->count().' affected line(s).',
        );
    }

    /**
     * api.document.045 (Codex round-2 Finding 1): the scoped lookup returns
     * null for cross-tenant product_ids, but the previous fix still wrote
     * the raw foreign UUID into document_lines.product_id. That made the
     * line dereference a foreign-tenant Product via DocumentLine::product()
     * (unscoped belongsTo) — concretely surfaced in PostCOGSOnInvoice and
     * other downstream consumers that read $line->product->is_physical etc.
     */
    public function test_auto_save_batch_lines_does_not_persist_cross_tenant_product_id(): void
    {
        $productB = Product::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'TenantBForeignProductId',
        ]);

        $this->actingAsForTenant()
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customerA->id,
                'lines' => [
                    ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 50],
                    ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 60],
                ],
            ])
            ->assertStatus(200);

        $linesWithForeignProductId = DocumentLine::query()
            ->where('product_id', $productB->id)
            ->count();

        $this->assertSame(
            0,
            $linesWithForeignProductId,
            'addLinesBatch must not persist a cross-tenant product_id; persist null when the scoped lookup misses.',
        );
    }

    public function test_auto_save_batch_lines_query_includes_tenant_and_company_predicates(): void
    {
        $productA = Product::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A Batch',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForTenant()
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customerA->id,
                'lines' => [
                    ['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 50],
                    ['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 60],
                ],
            ])
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $batchProductQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, 'from "products"') && str_contains($sql, 'in (')) {
                $batchProductQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $batchProductQuery,
            'addLinesBatch Product whereIn query must be captured. Log: '
            .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $batchProductQuery, 'addLinesBatch must filter by tenant_id. SQL: '.$batchProductQuery);
        $this->assertStringContainsString('"company_id"', $batchProductQuery, 'addLinesBatch must filter by company_id. SQL: '.$batchProductQuery);
    }

    public function test_return_note_service_uses_company_scoped_location_lookup(): void
    {
        $source = $this->readSource('app/Modules/Document/Domain/Services/ReturnNoteService.php');
        $this->assertStringContainsString('api.document.020', $source);
        $this->assertMatchesRegularExpression('/Location::query\(\)\s*->where\([\'"]company_id[\'"]/', $source);
    }

    public function test_delivery_note_service_uses_scoped_source_document_lookup(): void
    {
        $source = $this->readSource('app/Modules/Document/Domain/Services/DeliveryNoteService.php');
        $this->assertStringContainsString('api.document.021', $source);
        // Opus Finding D: require BOTH tenant_id AND company_id, not just tenant_id.
        $this->assertMatchesRegularExpression('/Document::query\(\)\s*->where\([\'"]tenant_id[\'"][^)]+\)\s*->where\([\'"]company_id[\'"]/', $source);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function readSource(string $relative): string
    {
        $contents = file_get_contents(base_path($relative));
        $this->assertNotFalse($contents, 'Source file must be readable: '.$relative);

        return $contents;
    }

    /**
     * Extract a method body from PHP source by signature prefix. Anchored on
     * the next "\n    }\n" terminator. Used for Codex round-1 Finding 2 to
     * confirm a per-callsite (not file-wide) regex match on scoped reads.
     */
    private function extractMethodBody(string $source, string $signaturePrefix): string
    {
        $start = strpos($source, $signaturePrefix);
        $this->assertNotFalse($start, 'Signature prefix must exist: '.$signaturePrefix);
        $end = strpos($source, "\n    }\n", $start);
        $this->assertNotFalse($end, 'Method must terminate: '.$signaturePrefix);

        return substr($source, $start, $end - $start);
    }

    private function actingAsForTenant(): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->userA->tenant_id);

        /** @var self */
        return $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Company-Id', $this->companyA->id);
    }
}
