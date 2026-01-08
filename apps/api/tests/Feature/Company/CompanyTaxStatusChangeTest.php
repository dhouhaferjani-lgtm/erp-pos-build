<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for company tax status change validation.
 *
 * Tax status changes affect VAT recoverability and fiscal compliance.
 * Once fiscal documents are posted, tax status becomes immutable to
 * prevent invalidating historical tax calculations and fiscal chains.
 *
 * This test suite verifies Phase 2 of the tax safeguards implementation.
 */
class CompanyTaxStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_status' => CompanyTaxStatus::NON_REGISTERED,
        ]);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user);
    }

    public function test_allows_tax_status_change_when_no_posted_documents(): void
    {
        // Arrange: No documents at all
        $this->assertEquals(CompanyTaxStatus::NON_REGISTERED, $this->company->tax_status);

        // Act: Change to REGISTERED
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change allowed
        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::REGISTERED, $this->company->tax_status);
    }

    public function test_allows_tax_status_change_when_only_draft_documents_exist(): void
    {
        // Arrange: Create draft invoice (not posted)
        $this->createDraftInvoice();

        // Act: Change tax status
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change allowed (drafts don't block)
        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::REGISTERED, $this->company->tax_status);
    }

    public function test_allows_tax_status_change_when_only_confirmed_but_not_posted_invoices(): void
    {
        // Arrange: Create confirmed invoice (not yet posted)
        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        // Act: Change tax status
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change allowed (only posted documents block)
        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::REGISTERED, $this->company->tax_status);
    }

    public function test_allows_tax_status_change_when_only_non_fiscal_documents_posted(): void
    {
        // Arrange: Create posted quote (non-fiscal document)
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Posted,
            'document_number' => 'QT-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '100.00',
        ]);

        // Act: Change tax status
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change allowed (quotes are not fiscal documents)
        $response->assertOk();
    }

    public function test_blocks_tax_status_change_when_posted_invoice_exists(): void
    {
        // Arrange: Create posted invoice
        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'posted_at' => now(),
        ]);

        // Act: Attempt to change tax status
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change blocked
        $response->assertStatus(422);
        $response->assertJson([
            'error' => [
                'code' => 'BUSINESS_ERROR',
            ],
        ]);

        // Assert: Error message is clear
        $this->assertStringContainsString(
            'posted fiscal documents exist',
            $response->json('error.message')
        );

        // Assert: Tax status unchanged
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::NON_REGISTERED, $this->company->tax_status);
    }

    public function test_blocks_tax_status_change_when_posted_credit_note_exists(): void
    {
        // Arrange: Create posted credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Posted,
            'document_number' => 'CN-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '-50.00',
            'posted_at' => now(),
        ]);

        // Act: Attempt to change tax status
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);

        // Assert: Change blocked
        $response->assertStatus(422);
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::NON_REGISTERED, $this->company->tax_status);
    }

    public function test_allows_other_company_updates_when_posted_documents_exist(): void
    {
        // Arrange: Create posted invoice (blocks tax status changes)
        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'posted_at' => now(),
        ]);

        // Act: Update other fields (not tax_status)
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'name' => 'Updated Company Name',
            'phone' => '123-456-7890',
            'email' => 'updated@example.com',
        ]);

        // Assert: Update successful
        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals('Updated Company Name', $this->company->name);
        $this->assertEquals('123-456-7890', $this->company->phone);
    }

    public function test_no_validation_error_when_tax_status_unchanged(): void
    {
        // Arrange: Create posted invoice
        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'posted_at' => now(),
        ]);

        // Act: Update with same tax status (no change)
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'NON_REGISTERED', // Same as current
            'name' => 'Updated Name',
        ]);

        // Assert: Update successful (validation skipped when no change)
        $response->assertOk();
        $this->company->refresh();
        $this->assertEquals('Updated Name', $this->company->name);
    }

    public function test_blocks_both_direction_changes_registered_to_non_registered(): void
    {
        // Arrange: Company is REGISTERED with posted invoice
        $this->company->update(['tax_status' => CompanyTaxStatus::REGISTERED]);

        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'posted_at' => now(),
        ]);

        // Act: Attempt to change REGISTERED → NON_REGISTERED
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'NON_REGISTERED',
        ]);

        // Assert: Change blocked (both directions)
        $response->assertStatus(422);
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::REGISTERED, $this->company->tax_status);
    }

    public function test_realistic_scenario_startup_grows_into_vat_registration(): void
    {
        // Scenario: Small business starts as NON_REGISTERED, grows, registers for VAT

        // Phase 1: Company starts, creates some quotes (non-fiscal)
        $this->assertEquals(CompanyTaxStatus::NON_REGISTERED, $this->company->tax_status);

        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '1000.00',
        ]);

        // Phase 2: Company decides to register for VAT (before any invoices)
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'REGISTERED',
        ]);
        $response->assertOk();

        // Phase 3: Now company issues first invoice with recoverable VAT
        $this->company->refresh();
        $this->assertEquals(CompanyTaxStatus::REGISTERED, $this->company->tax_status);

        $invoice = $this->createDraftInvoice();
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'posted_at' => now(),
        ]);

        // Phase 4: Tax status now locked (cannot change back)
        $response = $this->putJson("/api/v1/companies/{$this->company->id}", [
            'tax_status' => 'NON_REGISTERED',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Create a draft invoice for testing.
     */
    private function createDraftInvoice(): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-TEST-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
