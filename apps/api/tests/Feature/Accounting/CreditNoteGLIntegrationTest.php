<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CreditNoteGLIntegrationTest - TDD RED Phase
 *
 * Tests for credit note GL reversal entry creation following strict TDD principles.
 *
 * Expected behavior when credit note is posted:
 * 1. Create a JournalEntry record linked to the credit note
 * 2. Create JournalLine records that REVERSE the original invoice entries:
 *    - CR: Accounts Receivable (411) = Credit note total (opposite of invoice debit)
 *    - DR: Revenue (707/706) = Line subtotals (opposite of invoice credit)
 *    - DR: Tax Payable (44571) = Tax amounts (opposite of invoice credit)
 * 3. Ensure total debits = total credits (balanced entry)
 * 4. Handle multiple tax rates correctly
 * 5. Use correct account codes via SystemAccountPurpose
 * 6. Reference source invoice for audit trail
 *
 * CRITICAL: These tests MUST fail initially (RED phase).
 * Agent 6B will implement the createCreditNoteGLEntries() functionality to make them pass (GREEN phase).
 *
 * Context: Credit notes REVERSE invoice GL entries
 * Original Invoice:
 * - DR: AR €1190
 * - CR: Revenue €1000
 * - CR: Tax €190
 *
 * Credit Note (reversal):
 * - CR: AR €1190  ← Opposite of invoice
 * - DR: Revenue €1000  ← Opposite of invoice
 * - DR: Tax €190  ← Opposite of invoice
 */
class CreditNoteGLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Location $warehouse;

    private Account $receivableAccount;

    private Account $productRevenueAccount;

    private Account $serviceRevenueAccount;

    private Account $vatCollectedAccount;

    private Account $salesStampDutyAccount;

    private Product $product1;

    private Product $product2;

    private Product $service;

    private AccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Credit Note GL Test Tenant',
            'slug' => 'cn-gl-test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Credit Note GL Test Company',
            'legal_name' => 'Credit Note GL Test Company LLC',
            'tax_id' => 'TAX-CN-GL-TEST-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Setup permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user with permissions
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User CN GL',
            'email' => 'testuser-cn-gl-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'invoices.view',
            'invoices.create',
            'invoices.post',
            'credit-notes.view',
            'credit-notes.create',
            'credit-notes.post',
            'journal.view',
            'journal.create',
            'journal.post',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer CN GL',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            // locations.code is varchar(20); keep within length for PostgreSQL.
            'code' => 'WHC-'.substr((string) uniqid(), -10),
            'name' => 'Main Warehouse CN GL',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Create Chart of Accounts with SystemAccountPurpose
        $this->receivableAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Clients - Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->productRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Vente de marchandises',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->serviceRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706',
            'name' => 'Prestations de services',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);

        $this->vatCollectedAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '44571',
            'name' => 'TVA collectée',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        $this->salesStampDutyAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4375',
            'name' => 'Droit de timbre à reverser',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::SalesStampDutyPayable,
            'is_active' => true,
        ]);

        // Create test products
        $this->product1 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD1-CN-'.uniqid(),
            'name' => 'Product 1 - Standard VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD2-CN-'.uniqid(),
            'name' => 'Product 2 - Reduced VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->service = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SRV1-CN-'.uniqid(),
            'name' => 'Service - Labor',
            'type' => ProductType::Service,
            'selling_price' => '150.00',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Test: Credit note posting creates GL reversal entries that are opposite of invoice
     *
     * Expected GL Structure for Credit Note (reversal of invoice):
     * Credit Note: €1000 subtotal, €190 tax (19% VAT), €1190 total
     *
     * Expected Journal Entry (OPPOSITE of invoice):
     * - CR: AR (411)            €1190.00  (opposite of invoice DR)
     * - DR: Revenue (707)       €1000.00  (opposite of invoice CR)
     * - DR: Tax Payable (44571) €190.00   (opposite of invoice CR)
     */
    public function test_credit_note_posting_creates_gl_reversal_entries(): void
    {
        // Create credit note matching simple invoice structure
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '1000.00',
                'tax_rate' => '19.00', // 19% VAT
                'description' => 'Product return - full credit',
            ],
        ]);

        // ACT: Call the method that should create GL reversal entries
        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);

        // ASSERT: Journal Entry created
        $this->assertNotNull($journalEntryId);
        $journalEntry = JournalEntry::find($journalEntryId);
        $this->assertNotNull($journalEntry, 'JournalEntry should be created');
        $this->assertEquals($this->company->id, $journalEntry->company_id);
        $this->assertEquals($this->tenant->id, $journalEntry->tenant_id);
        $this->assertEquals('Document', $journalEntry->source_type);
        $this->assertEquals($creditNote->id, $journalEntry->source_id);

        // ASSERT: Journal Lines created (minimum 3: 1 AR credit + 1 Revenue debit + 1 Tax debit)
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();
        $this->assertGreaterThanOrEqual(3, $journalLines->count(), 'Should have at least 3 lines: 1 AR + 1 Revenue + 1 Tax');

        // ASSERT: AR credit line (OPPOSITE of invoice debit)
        $arLine = $journalLines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertEquals('0.000', $arLine->debit, 'AR should NOT be debited in credit note');
        $this->assertEquals('1190.000', $arLine->credit, 'AR should be credited for total credit note amount (REVERSAL)');

        // ASSERT: Revenue debit line (OPPOSITE of invoice credit)
        $revenueLines = $journalLines->where('account_id', $this->productRevenueAccount->id);
        $totalRevenueDebit = $revenueLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(1000.00, $totalRevenueDebit, 'Total revenue debits should equal subtotal (REVERSAL)');

        // ASSERT: VAT debit line (OPPOSITE of invoice credit)
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);
        $this->assertGreaterThanOrEqual(1, $vatLines->count(), 'Should have VAT line(s)');
        $totalVatDebit = $vatLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(190.00, $totalVatDebit, 'Total VAT debits should equal tax amount (REVERSAL)');
    }

    /**
     * Test: Credit note GL entry is balanced (total debits = total credits)
     *
     * This is fundamental to double-entry accounting.
     * The reversal must balance just like the original invoice.
     */
    public function test_credit_note_gl_entries_are_balanced(): void
    {
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '2',
                'unit_price' => '300.00',
                'tax_rate' => '19.00', // 19% VAT
                'description' => 'Test product return',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);

        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);

        // Expected: €600 subtotal + €114 VAT (19%) = €714 total
        $this->assertEquals($totalDebits, $totalCredits, 'Debits must equal credits (balanced entry)');
        $this->assertEquals(714.00, $totalDebits, 'Total debits should be €600 + €114 VAT = €714');
        $this->assertEquals(714.00, $totalCredits, 'Total credits should be €714 (AR credit)');
    }

    /**
     * Test: Credit note reverses multiple tax rates correctly
     *
     * Expected:
     * - 3 lines with different tax rates: 19%, 7%, 0%
     * - Each tax rate should have its own VAT debit line (reversal)
     * - Revenue should be debited (reversal)
     * - AR should be credited (reversal)
     */
    public function test_credit_note_reverses_multiple_tax_rates(): void
    {
        // Create credit note with 3 different tax rates (realistic French VAT rates)
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '19.00', // Standard VAT Tunisia
                'description' => 'Standard VAT 19%',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '7.00', // Reduced VAT Tunisia
                'description' => 'Reduced VAT 7%',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '0.00', // Zero-rated
                'description' => 'Zero VAT',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Check VAT debit lines (reversals)
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);

        // Expected VAT amounts (debited):
        // 19% of 100 = 19.00
        // 7% of 100 = 7.00
        // 0% of 100 = 0.00
        // Total VAT = 26.00

        $totalVatDebit = $vatLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(26.00, $totalVatDebit, 'Total VAT debits should be sum of all tax rates (REVERSAL)');

        // Should have at least 1 VAT debit line (may be grouped or separate per rate)
        $this->assertGreaterThanOrEqual(1, $vatLines->count());

        // Verify balance: Total debits = Total credits
        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals($totalDebits, $totalCredits);
    }

    /**
     * Test: Credit note reverses both product and service revenue correctly
     *
     * Service revenue uses different account (706) than product revenue (707).
     * Both should be debited (reversed) in credit note.
     */
    public function test_credit_note_reverses_product_and_service_revenue(): void
    {
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'description' => 'Physical product return',
            ],
            [
                'product' => $this->service,
                'quantity' => '1',
                'unit_price' => '150.00',
                'tax_rate' => '19.00',
                'description' => 'Service credit',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Should have both product revenue and service revenue debit lines (reversals)
        $accountIds = $journalLines->pluck('account_id')->unique();

        $this->assertTrue(
            $accountIds->contains($this->productRevenueAccount->id),
            'Should use Product Revenue account (707) with DEBIT'
        );

        $this->assertTrue(
            $accountIds->contains($this->serviceRevenueAccount->id),
            'Should use Service Revenue account (706) with DEBIT'
        );

        // Verify product revenue is debited (not credited)
        $productRevenueLine = $journalLines
            ->where('account_id', $this->productRevenueAccount->id)
            ->first();
        $this->assertGreaterThan(0, (float) $productRevenueLine->debit, 'Product revenue should be DEBITED (reversal)');
        $this->assertEquals(0.00, (float) $productRevenueLine->credit, 'Product revenue should NOT be credited');

        // Verify service revenue is debited (not credited)
        $serviceRevenueLine = $journalLines
            ->where('account_id', $this->serviceRevenueAccount->id)
            ->first();
        $this->assertGreaterThan(0, (float) $serviceRevenueLine->debit, 'Service revenue should be DEBITED (reversal)');
        $this->assertEquals(0.00, (float) $serviceRevenueLine->credit, 'Service revenue should NOT be credited');
    }

    /**
     * Test: Partial credit note creates proportional reversal
     *
     * If invoice was €1000, and credit note is €300 (partial return),
     * the GL reversal should be proportional (€300 + tax).
     */
    public function test_partial_credit_note_creates_proportional_reversal(): void
    {
        // Partial credit: Only 1 item out of original 3
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1', // Only 1 unit returned
                'unit_price' => '500.00',
                'tax_rate' => '19.00',
                'description' => 'Partial return - 1 unit',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Expected: €500 + €95 VAT = €595 total

        // AR should be credited for partial amount
        $arLine = $journalLines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('595.000', $arLine->credit, 'AR credit should match partial credit note total');

        // Revenue should be debited for partial amount
        $revenueLines = $journalLines->where('account_id', $this->productRevenueAccount->id);
        $totalRevenueDebit = $revenueLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(500.00, $totalRevenueDebit, 'Revenue debit should match partial credit subtotal');

        // VAT should be debited for partial amount
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);
        $totalVatDebit = $vatLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(95.00, $totalVatDebit, 'VAT debit should match partial credit tax amount');

        // Verify balanced
        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals($totalDebits, $totalCredits);
    }

    /**
     * Test: Credit note GL entry description includes credit note number
     *
     * Audit trail requirement: Entry description should reference the credit note number.
     */
    public function test_credit_note_gl_entry_references_credit_note_number(): void
    {
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'description' => 'Test return',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $journalEntry = JournalEntry::find($journalEntryId);

        $this->assertStringContainsString(
            $creditNote->document_number,
            $journalEntry->description,
            'Entry description should include credit note number for audit trail'
        );

        $this->assertStringContainsString(
            'Credit Note',
            $journalEntry->description,
            'Entry description should mention "Credit Note"'
        );
    }

    /**
     * Test: Zero tax credit note (tax-exempt) handles reversal correctly
     *
     * Should NOT create VAT debit lines for zero tax.
     */
    public function test_credit_note_handles_zero_tax_reversal_correctly(): void
    {
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '0.00', // Zero-rated
                'description' => 'Tax-exempt product return',
            ],
        ]);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Should NOT have VAT debit lines for zero tax
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);
        $totalVatDebit = $vatLines->sum(fn ($line) => (float) $line->debit);

        $this->assertEquals(0.00, $totalVatDebit, 'No VAT should be debited for zero tax rate');

        // Should still be balanced
        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals($totalDebits, $totalCredits);

        // Total should be €100 (no tax)
        $this->assertEquals(100.00, $totalDebits);
        $this->assertEquals(100.00, $totalCredits);
    }

    /**
     * Test: Multiple credit notes create separate GL entries
     *
     * Each credit note should create its own independent journal entry.
     */
    public function test_multiple_credit_notes_create_separate_gl_entries(): void
    {
        $creditNote1 = $this->createCreditNote([
            ['product' => $this->product1, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.00', 'description' => 'Credit Note 1'],
        ]);

        $creditNote2 = $this->createCreditNote([
            ['product' => $this->product2, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '19.00', 'description' => 'Credit Note 2'],
        ]);

        $entry1Id = $this->accountingService->createCreditNoteGLEntries($creditNote1);
        $entry2Id = $this->accountingService->createCreditNoteGLEntries($creditNote2);

        $this->assertNotEquals($entry1Id, $entry2Id, 'Each credit note should create a separate GL entry');

        $entry1 = JournalEntry::find($entry1Id);
        $entry2 = JournalEntry::find($entry2Id);

        $this->assertEquals($creditNote1->id, $entry1->source_id);
        $this->assertEquals($creditNote2->id, $entry2->source_id);
    }

    /**
     * Test: a credit note reverses the collected stamp duty (timbre) out of the
     * 4375 liability (Dr 4375), mirroring the invoice posting, and balances.
     *
     * Bug #5A (credit-note side): without this leg the AR credit carried the
     * timbre with no matching debit and the reversal was unbalanced.
     */
    public function test_credit_note_gl_reverses_collected_stamp_duty_from_4375_and_balances(): void
    {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-STAMP-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '21.000',  // 20 VAT + 1 timbre
            'total' => '121.000',
            'balance_due' => '121.000',
            'currency' => 'EUR',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Reversed product with VAT + timbre',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '20.00',
            'line_total' => '100.000',
        ]);
        $creditNote = $creditNote->fresh(['lines']);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Stamp duty debited (reversed) out of 4375.
        $stampLine = $lines->firstWhere('account_id', $this->salesStampDutyAccount->id);
        $this->assertNotNull($stampLine, 'Credit note must reverse stamp duty from the 4375 account');
        $this->assertEquals('1.000', $stampLine->debit);
        $this->assertEquals('0.000', $stampLine->credit);

        $debits = $lines->sum(fn ($l) => (float) $l->debit);
        $credits = $lines->sum(fn ($l) => (float) $l->credit);
        $this->assertEquals($debits, $credits, 'Credit note reversal must balance');
        $this->assertEquals(121.00, $credits);
    }

    /**
     * Q1 (2026-08-07 expert-comptable ruling) — RED/GREEN pin for the NEW shape:
     * a credit note carrying its OWN stamp duty (`documents.stamp_duty_amount`)
     * must credit 411 with the EX-STAMP amount only, and book the stamp as a
     * separate self-balancing pair (DEBIT the fiscal-charge expense account,
     * CREDIT the stamp-payable liability) — it no longer reduces what the
     * customer owes.
     *
     * Fixture: 1.000 net + 0.190 VAT (19%) + 0.600 stamp = 1.790 total.
     *
     * docs/superpowers/tickets/2026-08-06-expert-comptable-rulings-q2-q3.md §Q1
     * docs/superpowers/tickets/2026-08-03-credit-note-regate-carryovers.md §N1
     */
    public function test_credit_note_with_its_own_stamp_duty_credits_ar_ex_stamp_and_books_a_separate_fiscal_charge_pair(): void
    {
        $purchaseStampDutyAccount = $this->createPurchaseStampDutyAccount();

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-OWNSTAMP-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1.000',
            'stamp_duty_amount' => '0.600',
            'tax_amount' => '0.790',   // 0.190 line VAT + 0.600 stamp
            'total' => '1.790',
            'balance_due' => '1.790',
            'currency' => 'EUR',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Credited product, own-stamp CN',
            'quantity' => '1',
            'unit_price' => '1.000',
            'tax_rate' => '19.00',
            'line_total' => '1.000',
        ]);
        $creditNote = $creditNote->fresh(['lines']);

        // Consumer sweep (1/2): the L1 preflight must accept the new shape.
        $this->accountingService->assertDocumentGlIsPostable($creditNote);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // 411 credited EX-STAMP only: 1.000 + 0.190 = 1.190, NOT the stamp-inclusive 1.790.
        $arLine = $lines->firstWhere('account_id', $this->receivableAccount->id);
        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertSame('0.000', $arLine->debit);
        $this->assertSame('1.190', $arLine->credit, 'AR/411 must be credited ex-stamp only (Q1 ruling)');

        // Revenue reversal unchanged.
        $revenueLine = $lines->firstWhere('account_id', $this->productRevenueAccount->id);
        $this->assertSame('1.000', $revenueLine->debit);

        // Line VAT reversal unchanged — the stamp must NOT land on VatCollected.
        $vatLine = $lines->firstWhere('account_id', $this->vatCollectedAccount->id);
        $this->assertNotNull($vatLine);
        $this->assertSame('0.190', $vatLine->debit);

        // NEW self-balancing stamp pair.
        $stampChargeLine = $lines->firstWhere('account_id', $purchaseStampDutyAccount->id);
        $this->assertNotNull($stampChargeLine, 'A DEBIT leg on the fiscal-charge expense account must exist');
        $this->assertSame('0.600', $stampChargeLine->debit);
        $this->assertSame('0.000', $stampChargeLine->credit);

        $stampPayableLine = $lines->firstWhere('account_id', $this->salesStampDutyAccount->id);
        $this->assertNotNull($stampPayableLine, 'A CREDIT leg on the stamp-payable liability must exist');
        $this->assertSame('0.000', $stampPayableLine->debit);
        $this->assertSame('0.600', $stampPayableLine->credit);

        // Whole entry stays balanced, string-exact.
        $debits = '0';
        $credits = '0';
        foreach ($lines as $line) {
            $debits = bcadd($debits, (string) $line->debit, 3);
            $credits = bcadd($credits, (string) $line->credit, 3);
        }
        $this->assertSame($credits, $debits, 'Debits must equal credits (balanced entry)');
        $this->assertSame('1.790', $debits, 'Total debits must equal the document total');

        // Consumer sweep (2/2): cancelling the new-shape CN mirrors every leg
        // (including the stamp pair) and stays balanced.
        $postingService = app(DocumentPostingService::class);
        $postingService->cancel($creditNote->fresh(['lines']), 'test cancel', $this->user->id);

        $reversal = JournalEntry::query()
            ->where('source_type', AccountingService::DOCUMENT_CANCELLATION_SOURCE_TYPE)
            ->where('source_id', $creditNote->id)
            ->with('lines')
            ->first();
        $this->assertNotNull($reversal, 'Cancelling the credit note must write a reversing entry');

        $reversedStampCharge = $reversal->lines->firstWhere('account_id', $purchaseStampDutyAccount->id);
        $this->assertNotNull($reversedStampCharge, 'The stamp charge leg must be mirrored by the cancel-reversal');
        $this->assertSame('0.000', $reversedStampCharge->debit);
        $this->assertSame('0.600', $reversedStampCharge->credit);

        $reversedStampPayable = $reversal->lines->firstWhere('account_id', $this->salesStampDutyAccount->id);
        $this->assertNotNull($reversedStampPayable, 'The stamp payable leg must be mirrored by the cancel-reversal');
        $this->assertSame('0.600', $reversedStampPayable->debit);
        $this->assertSame('0.000', $reversedStampPayable->credit);

        $reversalDebits = '0';
        $reversalCredits = '0';
        foreach ($reversal->lines as $line) {
            $reversalDebits = bcadd($reversalDebits, (string) $line->debit, 3);
            $reversalCredits = bcadd($reversalCredits, (string) $line->credit, 3);
        }
        $this->assertSame($reversalCredits, $reversalDebits, 'The reversal must itself balance');
    }

    /**
     * Gate m-1 (2026-08-07) — when a stamp-bearing CN's leftover rounding
     * residual ALSO lands on the SAME `SalesStampDutyPayable` (4375) account
     * the new stamp pair uses (residualPlan()'s ladder still prefers 4375 for
     * the leftover, unchanged, to avoid a TN regression), that leg must be
     * labelled as rounding dust, never "stamp duty" — the real stamp already
     * has its own explicit pair with its own description.
     *
     * Fixture: same 1.000 net + 0.190 VAT + 0.600 stamp as the main test, but
     * `total` is bumped by 0.001 so a genuine (non-stamp) residual exists
     * alongside the stamp.
     */
    public function test_credit_note_residual_leg_is_labelled_rounding_dust_not_stamp_duty_when_a_stamp_pair_is_also_written(): void
    {
        $purchaseStampDutyAccount = $this->createPurchaseStampDutyAccount();

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-M1-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1.000',
            'stamp_duty_amount' => '0.600',
            'tax_amount' => '0.791',   // 0.190 line VAT + 0.600 stamp + 0.001 dust
            'total' => '1.791',        // one ULP above the exact 1.790
            'balance_due' => '1.791',
            // TND (scale 3), NOT this fixture's default EUR (scale 2) — the
            // whole point of this test is a 0.001 (third-decimal) residual,
            // which a scale-2 currency would truncate away before it ever
            // reached the ledger.
            'currency' => 'TND',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Credited product, stamp + rounding dust',
            'quantity' => '1',
            'unit_price' => '1.000',
            'tax_rate' => '19.00',
            'line_total' => '1.000',
        ]);
        $creditNote = $creditNote->fresh(['lines']);

        $journalEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Two DISTINCT lines on the same 4375 account: the stamp pair's
        // CREDIT (0.600, exact) and the residual ladder's DEBIT (0.001, dust).
        $stampPayableLines = $lines->where('account_id', $this->salesStampDutyAccount->id);
        $this->assertCount(2, $stampPayableLines, 'the stamp pair credit and the residual debit both land on 4375');

        $residualLine = $stampPayableLines->firstWhere('debit', '0.001');
        $this->assertNotNull($residualLine, 'the 0.001 rounding-dust leg must exist');
        $this->assertStringNotContainsString(
            'Stamp duty',
            $residualLine->description,
            'the residual leg must not claim to carry the stamp when a separate stamp pair is also written'
        );
        $this->assertStringContainsString('rounding', strtolower($residualLine->description));

        $stampPairLine = $stampPayableLines->firstWhere('credit', '0.600');
        $this->assertNotNull($stampPairLine, 'the exact stamp pair credit leg must exist');
        $this->assertStringContainsString('Stamp duty', $stampPairLine->description);

        $stampChargeLine = $lines->firstWhere('account_id', $purchaseStampDutyAccount->id);
        $this->assertNotNull($stampChargeLine);
        $this->assertSame('0.600', $stampChargeLine->debit);

        $debits = '0';
        $credits = '0';
        foreach ($lines as $line) {
            $debits = bcadd($debits, (string) $line->debit, 3);
            $credits = bcadd($credits, (string) $line->credit, 3);
        }
        $this->assertSame($credits, $debits, 'the entry must still balance with the extra rounding-dust leg');
        $this->assertSame('1.791', $debits);
    }

    /**
     * Q1 fail-closed branch: a credit note carries its own stamp duty, but this
     * chart has no `PurchaseStampDuty` account to carry the DEBIT (fiscal-charge)
     * leg — the base fixture in `setUp()` never creates one. The pre-flight must
     * refuse (422), leaving the document Confirmed/unsealed and re-postable,
     * rather than sealing a wrong or unbalanced entry.
     */
    public function test_credit_note_with_stamp_duty_refuses_to_post_when_the_chart_has_no_stamp_charge_account(): void
    {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-NOCHARGEACCT-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1.000',
            'stamp_duty_amount' => '0.600',
            'tax_amount' => '0.790',
            'total' => '1.790',
            'balance_due' => '1.790',
            'currency' => 'EUR',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Credited product, own-stamp CN, no charge account',
            'quantity' => '1',
            'unit_price' => '1.000',
            'tax_rate' => '19.00',
            'line_total' => '1.000',
        ]);
        $creditNote = $creditNote->fresh(['lines']);

        try {
            $this->accountingService->assertDocumentGlIsPostable($creditNote);
            $this->fail('assertDocumentGlIsPostable() must refuse a stamp-bearing credit note when the chart has no fiscal-charge account.');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::NoCreditNoteStampAccount, $e->refusal);
        }

        // Bypassing the preflight (defence in depth): posting directly must
        // never silently drop the stamp pair. A self-balancing pair is
        // invisible to the Σdebits==Σcredits guard when BOTH legs are omitted
        // together (this fixture's residual is exactly 0, so the entry would
        // otherwise balance while silently dropping the company's real fiscal
        // charge/liability) — an explicit throw is the only correct defence.
        try {
            $this->accountingService->createCreditNoteGLEntries($creditNote);
            $this->fail('createCreditNoteGLEntries() must never seal an entry that silently drops the stamp.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($creditNote->document_number, $e->getMessage());
        }

        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $creditNote->id)->count(),
            'No journal entry may survive for a document whose stamp pair could not be resolved.'
        );
    }

    /**
     * W-6 D1a (credit-note sibling) — `createCreditNoteGLEntries()` repeats the
     * invoice pattern and must refuse an unbalanced reversal for the same reason:
     * a negative residual is a bug, never a rounding artefact, and the entry is
     * sealed into the immutable GL hash chain the moment it is written.
     *
     * Ticket: docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md (D1a).
     */
    public function test_credit_note_gl_refuses_to_post_an_unbalanced_entry(): void
    {
        $entriesBefore = JournalEntry::query()->count();

        // Header total understates the line tax: 100.000 total credited to AR vs
        // 100.000 revenue + 19.000 VAT debited => residual -19.000.
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-UNBAL-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '100.000',
            'currency' => 'EUR',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $creditNote->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Line whose tax_rate the header does not carry',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.000',
        ]);
        $creditNote = $creditNote->fresh(['lines']);

        try {
            $this->accountingService->createCreditNoteGLEntries($creditNote);
            $this->fail('createCreditNoteGLEntries() must refuse an unbalanced entry, not post it.');
        } catch (UnbalancedJournalEntryException $e) {
            $this->assertStringContainsString($creditNote->document_number, $e->getMessage());
        }

        $this->assertSame($entriesBefore, JournalEntry::query()->count());
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $creditNote->id)->count(),
            'No journal entry may survive for an unbalanced credit-note posting.'
        );
    }

    // ==================== HELPER METHODS ====================

    /**
     * Q1 — the fiscal-charge (DEBIT) account for a credit note's own stamp duty.
     * NOT created in `setUp()` on purpose: the fail-closed test relies on its
     * absence to exercise the refusal branch.
     */
    private function createPurchaseStampDutyAccount(): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6354',
            'name' => 'Droits d\'enregistrement et de timbre',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::PurchaseStampDuty,
            'is_active' => true,
        ]);
    }

    /**
     * Create a posted credit note with lines
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createCreditNote(array $lines): Document
    {
        // Calculate totals
        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $line) {
            $lineSubtotal = bcmul($line['quantity'], $line['unit_price'], 2);
            $lineTax = bcmul($lineSubtotal, bcdiv($line['tax_rate'], '100', 4), 2);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $taxAmount = bcadd($taxAmount, $lineTax, 2);
        }

        $total = bcadd($subtotal, $taxAmount, 2);

        // Create credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-GL-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total, // Credit notes have negative balance_due
            'currency' => 'EUR',
        ]);

        // Create lines
        $lineNumber = 1;
        foreach ($lines as $lineData) {
            $lineSubtotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);

            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $creditNote->id,
                'product_id' => $lineData['product']->id,
                'line_number' => $lineNumber++,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'],
                'line_total' => $lineSubtotal,
            ]);
        }

        return $creditNote->fresh(['lines']);
    }
}
