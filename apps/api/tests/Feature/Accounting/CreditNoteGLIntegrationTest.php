<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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

    // ==================== HELPER METHODS ====================

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
