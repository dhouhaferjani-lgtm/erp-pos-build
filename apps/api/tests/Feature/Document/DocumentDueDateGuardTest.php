<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * DEV-QA-008 / DEV-QA-057 — the FE submits `issue_date` (not `document_date`),
 * so the `after_or_equal:document_date` guard on `due_date` never had a value to
 * compare against on create, and the rule was absent entirely on update. A quote
 * or purchase order could therefore be saved with a due date that precedes its
 * issue date. These tests pin the guard on BOTH the create and update paths, for
 * BOTH the quote and purchase-order endpoints, using the real FE `issue_date`
 * payload shape.
 */
class DocumentDueDateGuardTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Partner $supplier;

    private Document $quote;

    private Document $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Acme Supplies',
            'type' => PartnerType::Supplier,
            'code' => 'SUP-001',
        ]);

        $this->quote = $this->makeDraft(DocumentType::Quote, $this->customer, 'QT-2025-0001');
        $this->purchaseOrder = $this->makeDraft(DocumentType::PurchaseOrder, $this->supplier, 'PO-2025-0001');
    }

    private function makeDraft(DocumentType $type, Partner $partner, string $number): Document
    {
        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Original Line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        return $document;
    }

    /**
     * @return list<array<string, string>>
     */
    private function lines(): array
    {
        return [
            [
                'description' => 'Service',
                'quantity' => '1.00',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
            ],
        ];
    }

    // ----- Quote: create -----------------------------------------------------

    public function test_quote_create_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_quote_create_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => $this->lines(),
            ]);

        $response->assertCreated();
    }

    // ----- Quote: update -----------------------------------------------------

    public function test_quote_update_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_quote_update_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(15)->toDateString(),
            ]);

        $response->assertOk();
    }

    // ----- Purchase order: create -------------------------------------------

    public function test_purchase_order_create_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $this->supplier->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    public function test_purchase_order_create_accepts_due_date_on_or_after_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', [
                'partner_id' => $this->supplier->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'lines' => $this->lines(),
            ]);

        $response->assertCreated();
    }

    // ----- Purchase order: update -------------------------------------------

    public function test_purchase_order_update_rejects_due_date_before_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}", [
                'issue_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    // ----- Equality boundary (F5.1) ------------------------------------------

    public function test_quote_create_accepts_a_due_date_equal_to_the_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $response->assertCreated();
    }

    // ----- valid_until (F5.3) ------------------------------------------------

    public function test_quote_create_rejects_a_valid_until_before_the_issue_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'valid_until' => now()->subDay()->toDateString(),
                'lines' => $this->lines(),
            ]);

        $this->assertApiValidationErrors($response, ['valid_until']);
    }

    // ----- Partial PATCH: the stored document_date is the comparand (F2) -----

    public function test_quote_partial_update_rejects_a_due_date_before_the_stored_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);

        $this->assertNull(
            $this->quote->refresh()->due_date,
            'A refused partial PATCH must not have persisted the early due date.'
        );
    }

    public function test_quote_partial_update_accepts_a_due_date_equal_to_the_stored_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'due_date' => now()->toDateString(),
            ]);

        $response->assertOk();
    }

    public function test_quote_partial_update_rejects_a_valid_until_before_the_stored_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/quotes/{$this->quote->id}", [
                'valid_until' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['valid_until']);
    }

    public function test_purchase_order_partial_update_rejects_a_due_date_before_the_stored_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$this->purchaseOrder->id}", [
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    // ----- Second company (CLAUDE.md rule 22, F5.4) --------------------------

    public function test_the_partial_update_guard_applies_in_a_second_company(): void
    {
        $secondCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'legal_name' => 'Second Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $secondCompany->id,
            'role' => 'admin',
        ]);

        $secondCustomer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $secondCompany->id,
            'name' => 'Jane Roe',
            'type' => PartnerType::Customer,
        ]);

        $secondQuote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $secondCompany->id,
            'partner_id' => $secondCustomer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'QT-2025-0001',
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        DocumentLine::create([
            'document_id' => $secondQuote->id,
            'line_number' => 1,
            'description' => 'Original Line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $secondCompany->id)
            ->patchJson("/api/v1/quotes/{$secondQuote->id}", [
                'due_date' => now()->subDay()->toDateString(),
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);

        $this->assertNull(
            $secondQuote->refresh()->due_date,
            'The guard must hold in the second company, not just the first.'
        );
    }

    // ----- Draft auto-save (F3) ---------------------------------------------

    public function test_auto_save_rejects_a_due_date_before_the_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'due_date' => now()->subDay()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);

        $this->assertSame(
            2,
            Document::query()->count(),
            'A refused auto-save must not author a third document (the two setUp drafts stand).'
        );
    }

    public function test_auto_save_accepts_a_due_date_equal_to_the_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'document_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $response->assertOk();
    }

    public function test_auto_save_accepts_a_payload_that_carries_no_dates(): void
    {
        // A draft mid-typing is legitimately incomplete: the guard must not turn
        // a dateless keystroke auto-save into a 422 that strands the operator's
        // work (useDraftAutoSave.ts:270-280 surfaces a failure as
        // `autosaveFailed` and saves nothing).
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $response->assertOk();
    }

    public function test_auto_save_rejects_a_due_date_before_the_stored_draft_document_date(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'draft_id' => $this->quote->id,
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'due_date' => now()->subDay()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);
    }

    // ----- Gate r2 N-1: the auto-save CREATE branch, and confirm ------------

    /**
     * Reviewer PROBE R1 — a brand-new draft, `document_date` omitted entirely.
     *
     * `DraftPersistenceService::createNewDraft()` substitutes
     * `now()->format('Y-m-d')` (`:261`), so the comparand is known exactly; it
     * was not "unknown", which is why the rule used to stay silent and the draft
     * was born with a due date a month before its document date.
     *
     * The refusal lands at the AUTO-SAVE, so the confirm in PROBE R2 is never
     * reached: no row is authored at all.
     */
    public function test_auto_save_rejects_a_due_date_before_today_on_a_brand_new_draft(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'due_date' => now()->subMonth()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);

        $this->assertSame(
            2,
            Document::query()->count(),
            'A refused auto-save must not author a third document (the two setUp drafts stand).'
        );
    }

    /**
     * Reviewer PROBE R3 — the byte-exact frontend payload. `DocumentForm.tsx:248`
     * emits `document_date: ''` when the operator clears the Issue Date input,
     * and Laravel's global `ConvertEmptyStringsToNull` turns that into null
     * before validation. Same refusal, same reason.
     */
    public function test_auto_save_rejects_a_due_date_before_today_when_the_issue_date_was_cleared(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'document_date' => '',
                'due_date' => now()->subMonth()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['due_date']);

        $this->assertSame(2, Document::query()->count());
    }

    public function test_auto_save_accepts_a_due_date_from_today_on_a_brand_new_draft(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/documents/auto-save', [
                'type' => DocumentType::Quote->value,
                'partner_id' => $this->customer->id,
                'due_date' => now()->toDateString(),
                'lines' => [
                    ['description' => 'Service', 'quantity' => '1.00', 'unit_price' => '100.00'],
                ],
            ]);

        $response->assertOk();
        $this->assertSame(3, Document::query()->count());
    }

    /**
     * Reviewer PROBE R2, defence in depth. Every `confirm()` action takes a bare
     * `Illuminate\Http\Request` and re-validates no dates, so a row that reached
     * the database by ANY other route was confirmed and NUMBERED with the exact
     * inconsistency this lane refuses. `DocumentStatusService::transition()` —
     * the one place a document changes status and the one place it is numbered —
     * now refuses the `Draft -> Confirmed` edge.
     *
     * The fixture is written straight to the table on purpose: that is what a
     * legacy draft, an importer, or a future writer looks like from confirm's
     * point of view.
     */
    public function test_confirming_a_quote_whose_due_date_precedes_its_document_date_is_refused(): void
    {
        $draft = $this->makeUnnumberedDraft(DocumentType::Quote, $this->customer, [
            'due_date' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$draft->id}/confirm");

        $response->assertStatus(422);

        $draft->refresh();

        $this->assertSame(
            DocumentStatus::Draft,
            $draft->status,
            'A refused confirm must leave the document a draft.'
        );
        $this->assertNull(
            $draft->document_number,
            'A refused confirm must not burn a number out of the quote sequence.'
        );
    }

    public function test_confirming_a_quote_whose_valid_until_precedes_its_document_date_is_refused(): void
    {
        $draft = $this->makeUnnumberedDraft(DocumentType::Quote, $this->customer, [
            'valid_until' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$draft->id}/confirm");

        $response->assertStatus(422);
        $this->assertSame(DocumentStatus::Draft, $draft->refresh()->status);
        $this->assertNull($draft->document_number);
    }

    public function test_confirming_a_purchase_order_whose_due_date_precedes_its_document_date_is_refused(): void
    {
        $draft = $this->makeUnnumberedDraft(DocumentType::PurchaseOrder, $this->supplier, [
            'due_date' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$draft->id}/confirm");

        $response->assertStatus(422);
        $this->assertSame(DocumentStatus::Draft, $draft->refresh()->status);
        $this->assertNull($draft->document_number);
    }

    /**
     * The positive control for the confirm guard: a consistent draft must still
     * confirm and still receive its number. Without this, a guard that refused
     * EVERY confirm would look green.
     */
    public function test_confirming_a_quote_with_consistent_dates_still_allocates_a_number(): void
    {
        $draft = $this->makeUnnumberedDraft(DocumentType::Quote, $this->customer, [
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/quotes/{$draft->id}/confirm")
            ->assertOk();

        $draft->refresh();

        $this->assertSame(DocumentStatus::Confirmed, $draft->status);
        $this->assertNotNull($draft->document_number);
    }

    /**
     * @param  array<string, string>  $dates
     */
    private function makeUnnumberedDraft(DocumentType $type, Partner $partner, array $dates): Document
    {
        // R-2 / LEDGER D-T9-1: a draft is born WITHOUT a number, so the
        // "no number was burned" assertions above are meaningful.
        $document = Document::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'document_number' => null,
            'document_date' => now()->toDateString(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ], $dates));

        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Original Line',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.00',
        ]);

        return $document;
    }
}
