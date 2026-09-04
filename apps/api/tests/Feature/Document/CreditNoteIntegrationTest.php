<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
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

class CreditNoteIntegrationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create country
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'domain' => 'test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Setup permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['credit-notes.view', 'credit-notes.create', 'invoices.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create chart of accounts
        $this->createChartOfAccounts();
    }

    /** @test */
    public function it_creates_credit_note_from_posted_invoice(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1190.00',
                'reason' => 'return',
                'notes' => 'Full refund - product return',
            ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Credit note created successfully',
            ])
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'document_number',
                    'document_date',
                    'source_invoice_id',
                    'source_invoice_number',
                    'partner',
                    'currency',
                    'subtotal',
                    'tax_amount',
                    'total',
                    'reason',
                    'reason_label',
                    'notes',
                    'status',
                ],
            ]);

        $creditNoteId = $response->json('data.id');

        // Verify credit note in database
        $this->assertDatabaseHas('documents', [
            'id' => $creditNoteId,
            'type' => DocumentType::CreditNote->value,
            'status' => DocumentStatus::Draft->value,
            'source_document_id' => $invoice->id,
            'total' => '1190.00',
            'credit_note_reason' => CreditNoteReason::RETURN->value,
            'notes' => 'Full refund - product return',
        ]);

        // Drafts are abandonable and therefore unnumbered until confirm.
        $creditNote = Document::find($creditNoteId);
        $this->assertNull($creditNote->document_number);
    }

    /** @test */
    public function it_creates_partial_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '595.00', // Half of total
                'reason' => 'price_adjustment',
                'notes' => 'Partial refund',
            ]);

        $response->assertCreated();

        $creditNote = Document::find($response->json('data.id'));
        $this->assertEquals('595.000', $creditNote->total);
        $this->assertEquals(CreditNoteReason::PRICE_ADJUSTMENT, $creditNote->credit_note_reason);
    }

    /** @test */
    public function it_lists_credit_notes_for_invoice(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create two credit notes
        $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '500.00',
                'reason' => 'return',
            ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '300.00',
                'reason' => 'price_adjustment',
            ]);

        // List credit notes for this invoice
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/credit-notes?source_invoice_id={$invoice->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_shows_single_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1190.00',
                'reason' => 'return',
                'notes' => 'Full refund',
            ]);

        $creditNoteId = $createResponse->json('data.id');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/credit-notes/{$creditNoteId}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $creditNoteId,
                    'source_invoice_id' => $invoice->id,
                    'source_invoice_number' => 'INV-001',
                    'total' => '1190.00',
                    'reason' => 'return',
                    'notes' => 'Full refund',
                ],
            ]);
    }

    /** @test */
    public function it_prevents_credit_note_exceeding_invoice_total(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1500.00', // Exceeds invoice total
                'reason' => 'return',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Credit note amount cannot exceed invoice total',
                ],
            ]);
    }

    /** @test */
    public function it_prevents_cumulative_credit_notes_exceeding_invoice_total(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create first credit note for 800
        $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '800.00',
                'reason' => 'price_adjustment',
            ]);

        // Try to create second credit note for 500 (total would be 1300 > 1190)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '500.00',
                'reason' => 'return',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Total credit notes would exceed invoice total',
                ],
            ]);
    }

    /** @test */
    public function it_prevents_credit_note_for_draft_invoice(): void
    {
        $invoice = $this->createDraftInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '1190.00',
                'reason' => 'return',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Credit notes can only be created for posted or paid invoices',
                ],
            ]);
    }

    /** @test */
    public function it_validates_credit_note_request(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                // Missing amount
                'reason' => 'return',
            ]);

        $this->assertApiValidationErrors($response, ['amount']);
    }

    /** @test */
    public function it_calculates_proportional_tax_for_partial_credit_note(): void
    {
        // Invoice: 1000 subtotal + 190 tax (19%) = 1190 total
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '595.00', // Half of total
                'reason' => 'price_adjustment',
            ]);

        $response->assertCreated();

        $creditNote = Document::find($response->json('data.id'));

        // Should calculate proportional tax
        // 595 / 1.19 = 500 subtotal
        // 595 - 500 = 95 tax
        $this->assertEquals('500.000', $creditNote->subtotal);
        $this->assertEquals('95.000', $creditNote->tax_amount);
        $this->assertEquals('595.000', $creditNote->total);
    }

    /** @test */
    public function it_generates_sequential_credit_note_numbers_at_confirm(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        $response1 = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '400.00',
                'reason' => 'return',
            ]);

        $response2 = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'amount' => '300.00',
                'reason' => 'return',
            ]);

        $this->assertNull($response1->json('data.document_number'));
        $this->assertNull($response2->json('data.document_number'));

        $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes/'.$response1->json('data.id').'/confirm')
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes/'.$response2->json('data.id').'/confirm')
            ->assertOk();

        $cn1Number = Document::query()->findOrFail($response1->json('data.id'))->document_number;
        $cn2Number = Document::query()->findOrFail($response2->json('data.id'))->document_number;

        $this->assertIsString($cn1Number);
        $this->assertIsString($cn2Number);
        $this->assertStringStartsWith('CN-', $cn1Number);
        $this->assertStringStartsWith('CN-', $cn2Number);
        $this->assertNotEquals($cn1Number, $cn2Number);

        // Documents-defects lane defect 1 fix (2026-08-02): credit-note
        // numbering is now unified onto DocumentNumberingService, format
        // CN-{year}-{seq} (e.g. CN-2026-0001), same as every other document
        // type -- the old CN-{seq}-only format (and this test's original
        // `/CN-(\d+)/` regex, which greedily matched the YEAR segment of the
        // new format) is retired. Extract the trailing sequence segment and
        // verify it is monotonic.
        preg_match('/CN-\d+-(\d+)$/', $cn1Number, $matches1);
        preg_match('/CN-\d+-(\d+)$/', $cn2Number, $matches2);

        $this->assertEquals((int) $matches1[1] + 1, (int) $matches2[1]);
    }

    /** @test */
    public function it_creates_credit_note_with_line_items(): void
    {
        $invoice = $this->createPostedInvoiceWithLines('INV-001', '1000.00', '190.00', '1190.00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/credit-notes', [
                'source_invoice_id' => $invoice->id,
                'reason' => 'return',
                'lines' => [
                    [
                        'line_id' => $invoice->lines->first()->id,
                        'quantity' => 5,
                    ],
                ],
                'notes' => 'Partial return',
            ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Credit note created successfully',
            ]);

        $creditNoteId = $response->json('data.id');
        $creditNote = Document::find($creditNoteId);

        // Verify credit note was created
        $this->assertNotNull($creditNote);
        $this->assertEquals(DocumentType::CreditNote, $creditNote->type);
        $this->assertEquals('Partial return', $creditNote->notes);
    }

    /** @test */
    public function it_confirms_draft_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create a draft credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CN-001',
            'document_date' => now(),
            'source_document_id' => $invoice->id,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/credit-notes/{$creditNote->id}/confirm");

        $response->assertOk()
            ->assertJson([
                'message' => 'Credit note confirmed successfully',
            ]);

        // Verify credit note was confirmed
        $creditNote->refresh();
        $this->assertEquals(DocumentStatus::Confirmed, $creditNote->status);
        $this->assertNotNull($creditNote->confirmed_at);
        $this->assertEquals($this->user->id, $creditNote->confirmed_by);
    }

    /** @test */
    public function it_prevents_confirming_already_confirmed_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create an already confirmed credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CN-001',
            'document_date' => now(),
            'source_document_id' => $invoice->id,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'currency' => 'TND',
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
        ]);

        // Should be idempotent - return success
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/credit-notes/{$creditNote->id}/confirm");

        $response->assertOk();
    }

    /** @test */
    public function it_posts_confirmed_credit_note(): void
    {
        // Add missing service_revenue account required by posting service
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706',
            'name' => 'Service Revenue',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);

        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create a confirmed credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CN-001',
            'document_date' => now(),
            'source_document_id' => $invoice->id,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'currency' => 'TND',
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
        ]);

        // A real credit note carries lines; revenue (500) + VAT (19% = 95) cover
        // the 595 total so the reversal GL balances with no stamp residual.
        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'Credited item',
            'quantity' => '1',
            'unit_price' => '500.000',
            'tax_rate' => '19.00',
            'line_total' => '500.000',
        ]);

        $this->user->givePermissionTo('credit-notes.post');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/credit-notes/{$creditNote->id}/post");

        $response->assertOk()
            ->assertJson([
                'message' => 'Credit note posted successfully',
            ]);

        // Verify credit note was posted
        $creditNote->refresh();
        $this->assertEquals(DocumentStatus::Posted, $creditNote->status);
        $this->assertNotNull($creditNote->fiscal_hash);
        $this->assertNotNull($creditNote->chain_sequence);
    }

    /** @test */
    public function it_prevents_posting_draft_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '190.00', '1190.00');

        // Create a draft credit note (not confirmed)
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CN-001',
            'document_date' => now(),
            'source_document_id' => $invoice->id,
            'subtotal' => '500.00',
            'tax_amount' => '95.00',
            'total' => '595.00',
            'currency' => 'TND',
        ]);

        $this->user->givePermissionTo('credit-notes.post');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/credit-notes/{$creditNote->id}/post");

        $response->assertUnprocessable()
            ->assertJson([
                'error' => [
                    'code' => 'CREDIT_NOTE_NOT_CONFIRMED',
                ],
            ]);
    }

    private function createPostedInvoice(
        string $number,
        string $subtotal,
        string $taxAmount,
        string $total
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    private function createDraftInvoice(
        string $number,
        string $subtotal,
        string $taxAmount,
        string $total
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    private function createPostedInvoiceWithLines(
        string $number,
        string $subtotal,
        string $taxAmount,
        string $total
    ): Document {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);

        // Create invoice lines
        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '10',
            'unit_price' => '100.00',
            'tax_rate' => '19',
            'line_total' => '1000.00',
        ]);

        return $invoice->load('lines');
    }

    private function createChartOfAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customers',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Sales',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4457',
            'name' => 'VAT Collected',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '709',
            'name' => 'Sales Returns',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::SalesReturn,
            'is_active' => true,
        ]);
    }
}
