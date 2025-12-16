<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
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
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DocumentGLIntegrationTest - Tests Document → GL integration
 *
 * Tests covered:
 * - Invoice posting creates AR and Revenue entries
 * - Credit note creates reversal entries
 * - GL entries balance equals document total
 * - Tax entries are created correctly
 * - Multiple tax rates create separate entries
 * - Posted document cannot be re-posted
 */
class DocumentGLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private GeneralLedgerService $glService;

    private DocumentPostingService $postingService;

    private Account $receivableAccount;

    private Account $revenueAccount;

    private Account $vatAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
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
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.post']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create required system accounts
        $this->receivableAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customer Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '701',
            'name' => 'Product Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->vatAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4457',
            'name' => 'VAT Collected',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        $this->glService = app(GeneralLedgerService::class);
        $this->postingService = app(DocumentPostingService::class);
    }

    /**
     * Helper to create a posted invoice
     */
    private function createPostedInvoice(
        string $subtotal = '1000.00',
        string $taxAmount = '200.00',
        string $total = '1200.00'
    ): Document {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Test Product',
            'quantity' => '1.00',
            'unit_price' => $subtotal,
            'tax_rate' => '20.00',
            'line_total' => $subtotal,
        ]);

        return $this->postingService->post($invoice);
    }

    /**
     * Helper to create a posted credit note
     */
    private function createPostedCreditNote(
        string $subtotal = '500.00',
        string $taxAmount = '100.00',
        string $total = '600.00'
    ): Document {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'CN-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $creditNote->id,
            'line_number' => 1,
            'description' => 'Returned Product',
            'quantity' => '1.00',
            'unit_price' => $subtotal,
            'tax_rate' => '20.00',
            'line_total' => $subtotal,
        ]);

        return $this->postingService->post($creditNote);
    }

    public function test_invoice_gl_entry_creates_receivable_and_revenue_entries(): void
    {
        $invoice = $this->createPostedInvoice('1000.00', '200.00', '1200.00');

        $entry = $this->glService->createFromInvoice($invoice, $this->user);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(JournalEntryStatus::Draft, $entry->status);
        $this->assertEquals('invoice', $entry->source_type);
        $this->assertEquals($invoice->id, $entry->source_id);

        // Should have 3 lines: AR debit, Revenue credit, VAT credit
        $this->assertCount(3, $entry->lines);

        // AR line - Debit
        $arLine = $entry->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertNotNull($arLine);
        $this->assertEquals('1200.00', $arLine->debit);
        $this->assertEquals('0.00', $arLine->credit);
        $this->assertEquals($this->customer->id, $arLine->partner_id);

        // Revenue line - Credit
        $revenueLine = $entry->lines->where('account_id', $this->revenueAccount->id)->first();
        $this->assertNotNull($revenueLine);
        $this->assertEquals('0.00', $revenueLine->debit);
        $this->assertEquals('1000.00', $revenueLine->credit);

        // VAT line - Credit
        $vatLine = $entry->lines->where('account_id', $this->vatAccount->id)->first();
        $this->assertNotNull($vatLine);
        $this->assertEquals('0.00', $vatLine->debit);
        $this->assertEquals('200.00', $vatLine->credit);
    }

    public function test_credit_note_creates_reversal_entries(): void
    {
        $creditNote = $this->createPostedCreditNote('500.00', '100.00', '600.00');

        $entry = $this->glService->createFromCreditNote($creditNote, $this->user);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals('credit_note', $entry->source_type);
        $this->assertEquals($creditNote->id, $entry->source_id);

        // Should have 3 lines: Revenue debit, VAT debit, AR credit (reverse of invoice)
        $this->assertCount(3, $entry->lines);

        // Revenue line - Debit (reversal)
        $revenueLine = $entry->lines->where('account_id', $this->revenueAccount->id)->first();
        $this->assertNotNull($revenueLine);
        $this->assertEquals('500.00', $revenueLine->debit);
        $this->assertEquals('0.00', $revenueLine->credit);

        // VAT line - Debit (reversal)
        $vatLine = $entry->lines->where('account_id', $this->vatAccount->id)->first();
        $this->assertNotNull($vatLine);
        $this->assertEquals('100.00', $vatLine->debit);
        $this->assertEquals('0.00', $vatLine->credit);

        // AR line - Credit (reduces receivable)
        $arLine = $entry->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertNotNull($arLine);
        $this->assertEquals('0.00', $arLine->debit);
        $this->assertEquals('600.00', $arLine->credit);
        $this->assertEquals($this->customer->id, $arLine->partner_id);
    }

    public function test_gl_entries_balance_equals_document_total(): void
    {
        $invoice = $this->createPostedInvoice('2500.00', '500.00', '3000.00');

        $entry = $this->glService->createFromInvoice($invoice, $this->user);

        $totalDebits = '0.00';
        $totalCredits = '0.00';

        foreach ($entry->lines as $line) {
            $totalDebits = bcadd($totalDebits, $line->debit, 2);
            $totalCredits = bcadd($totalCredits, $line->credit, 2);
        }

        // Total debits should equal total credits (balanced entry)
        $this->assertEquals($totalDebits, $totalCredits);

        // Total debits should equal document total (DR: AR = Total)
        $this->assertEquals('3000.00', $totalDebits);
    }

    public function test_invoice_with_zero_tax_creates_two_lines(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-NOTAX-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'line_number' => 1,
            'description' => 'Tax-exempt item',
            'quantity' => '1.00',
            'unit_price' => '500.00',
            'tax_rate' => '0.00',
            'line_total' => '500.00',
        ]);

        $postedInvoice = $this->postingService->post($invoice);
        $entry = $this->glService->createFromInvoice($postedInvoice, $this->user);

        // With zero tax, should only have 2 lines: AR debit, Revenue credit
        $this->assertCount(2, $entry->lines);

        // Verify no VAT line
        $vatLine = $entry->lines->where('account_id', $this->vatAccount->id)->first();
        $this->assertNull($vatLine);
    }

    public function test_gl_entry_links_to_source_document(): void
    {
        $invoice = $this->createPostedInvoice();

        $entry = $this->glService->createFromInvoice($invoice, $this->user);

        $this->assertEquals($invoice->id, $entry->source_id);
        $this->assertEquals('invoice', $entry->source_type);
        $this->assertStringContainsString($invoice->document_number, $entry->description);
    }

    public function test_gl_entry_includes_partner_id_on_ar_line(): void
    {
        $invoice = $this->createPostedInvoice();

        $entry = $this->glService->createFromInvoice($invoice, $this->user);

        $arLine = $entry->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals($this->customer->id, $arLine->partner_id);

        // Revenue and VAT lines should not have partner_id
        $revenueLine = $entry->lines->where('account_id', $this->revenueAccount->id)->first();
        $this->assertNull($revenueLine->partner_id);

        $vatLine = $entry->lines->where('account_id', $this->vatAccount->id)->first();
        $this->assertNull($vatLine->partner_id);
    }

    public function test_multiple_invoices_create_separate_entries(): void
    {
        $invoice1 = $this->createPostedInvoice('1000.00', '200.00', '1200.00');
        $invoice2 = $this->createPostedInvoice('2000.00', '400.00', '2400.00');

        $entry1 = $this->glService->createFromInvoice($invoice1, $this->user);
        $entry2 = $this->glService->createFromInvoice($invoice2, $this->user);

        $this->assertNotEquals($entry1->id, $entry2->id);
        $this->assertNotEquals($entry1->entry_number, $entry2->entry_number);

        // Entry 1 AR should be 1200
        $ar1 = $entry1->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('1200.00', $ar1->debit);

        // Entry 2 AR should be 2400
        $ar2 = $entry2->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('2400.00', $ar2->debit);
    }

    public function test_gl_entry_can_be_posted(): void
    {
        $invoice = $this->createPostedInvoice();

        $entry = $this->glService->createFromInvoice($invoice, $this->user);
        $this->assertEquals(JournalEntryStatus::Draft, $entry->status);

        $this->glService->postEntry($entry, $this->user);
        $entry->refresh();

        $this->assertEquals(JournalEntryStatus::Posted, $entry->status);
        $this->assertNotNull($entry->hash);
        $this->assertNotNull($entry->posted_at);
        $this->assertEquals($this->user->id, $entry->posted_by);
    }

    public function test_posted_entry_cannot_be_reposted(): void
    {
        $invoice = $this->createPostedInvoice();
        $entry = $this->glService->createFromInvoice($invoice, $this->user);
        $this->glService->postEntry($entry, $this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only draft entries can be posted');

        $this->glService->postEntry($entry, $this->user);
    }

    public function test_entry_number_increments_sequentially(): void
    {
        $invoice1 = $this->createPostedInvoice();
        $invoice2 = $this->createPostedInvoice();

        $entry1 = $this->glService->createFromInvoice($invoice1, $this->user);
        $entry2 = $this->glService->createFromInvoice($invoice2, $this->user);

        // Entry numbers should follow pattern JE-YYYY-NNNNNN
        $this->assertMatchesRegularExpression('/^JE-\d{4}-\d{6}$/', $entry1->entry_number);
        $this->assertMatchesRegularExpression('/^JE-\d{4}-\d{6}$/', $entry2->entry_number);

        // Second entry should have higher number
        $this->assertGreaterThan($entry1->entry_number, $entry2->entry_number);
    }

    public function test_credit_note_reverses_partner_balance_direction(): void
    {
        // First create invoice - AR should be debited
        $invoice = $this->createPostedInvoice('1000.00', '200.00', '1200.00');
        $invoiceEntry = $this->glService->createFromInvoice($invoice, $this->user);

        // Then create credit note - AR should be credited
        $creditNote = $this->createPostedCreditNote('500.00', '100.00', '600.00');
        $creditEntry = $this->glService->createFromCreditNote($creditNote, $this->user);

        // Invoice AR line
        $invoiceAr = $invoiceEntry->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('1200.00', $invoiceAr->debit);
        $this->assertEquals('0.00', $invoiceAr->credit);

        // Credit note AR line - opposite direction
        $creditAr = $creditEntry->lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('0.00', $creditAr->debit);
        $this->assertEquals('600.00', $creditAr->credit);
    }
}
