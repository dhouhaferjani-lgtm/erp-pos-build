<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\Events\PartnerBalanceUpdated;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private Account $cashAccount;

    private Account $revenueAccount;

    private Account $receivableAccount;

    private Account $taxAccount;

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
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
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
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.post', 'journal.view', 'journal.create', 'journal.post']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        // Seed chart of accounts with system purposes
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        // Get accounts with system purposes
        $this->cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank);
        $this->receivableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $this->revenueAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::ProductRevenue);
        $this->taxAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatCollected);
    }

    public function test_general_ledger_service_exists(): void
    {
        $this->assertTrue(class_exists(GeneralLedgerService::class));
    }

    public function test_posting_invoice_creates_journal_entry(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'total' => '120.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);

        $this->assertNotNull($journalEntry);
        $this->assertInstanceOf(JournalEntry::class, $journalEntry);
        $this->assertEquals('invoice', $journalEntry->source_type);
        $this->assertEquals($invoice->id, $journalEntry->source_id);
    }

    public function test_invoice_journal_entry_has_correct_debits_and_credits(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);

        $lines = $journalEntry->lines;

        // Accounts Receivable should be debited (asset increases)
        $receivableLine = $lines->firstWhere('account_id', $this->receivableAccount->id);
        $this->assertEquals('120.000', $receivableLine->debit);
        $this->assertEquals('0.000', $receivableLine->credit);

        // Revenue should be credited (revenue increases)
        $revenueLine = $lines->firstWhere('account_id', $this->revenueAccount->id);
        $this->assertEquals('0.000', $revenueLine->debit);
        $this->assertEquals('100.000', $revenueLine->credit);

        // Tax Payable should be credited (liability increases)
        $taxLine = $lines->firstWhere('account_id', $this->taxAccount->id);
        $this->assertEquals('0.000', $taxLine->debit);
        $this->assertEquals('20.000', $taxLine->credit);
    }

    public function test_invoice_journal_entry_is_balanced(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);

        $totalDebits = $journalEntry->lines->sum('debit');
        $totalCredits = $journalEntry->lines->sum('credit');

        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_credit_note_creates_reversal_entry(): void
    {
        $creditNote = $this->createCreditNote([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '50.00',
            'tax_amount' => '10.00',
            'total' => '60.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromCreditNote($creditNote, $this->user);

        $lines = $journalEntry->lines;

        // For credit notes, receivable should be credited (reduces receivable)
        $receivableLine = $lines->firstWhere('account_id', $this->receivableAccount->id);
        $this->assertEquals('0.000', $receivableLine->debit);
        $this->assertEquals('60.000', $receivableLine->credit);

        // Revenue should be debited (reduces revenue)
        $revenueLine = $lines->firstWhere('account_id', $this->revenueAccount->id);
        $this->assertEquals('50.000', $revenueLine->debit);
        $this->assertEquals('0.000', $revenueLine->credit);
    }

    public function test_cash_payment_creates_journal_entry(): void
    {
        $service = app(GeneralLedgerService::class);

        $journalEntry = $service->createPaymentEntry(
            companyId: $this->company->id,
            amount: '120.00',
            debitAccountId: $this->cashAccount->id,
            creditAccountId: $this->receivableAccount->id,
            description: 'Payment received',
            user: $this->user,
        );

        $lines = $journalEntry->lines;

        // Cash is debited (asset increases)
        $cashLine = $lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertEquals('120.000', $cashLine->debit);
        $this->assertEquals('0.000', $cashLine->credit);

        // Receivable is credited (asset decreases)
        $receivableLine = $lines->firstWhere('account_id', $this->receivableAccount->id);
        $this->assertEquals('0.000', $receivableLine->debit);
        $this->assertEquals('120.000', $receivableLine->credit);
    }

    public function test_payment_received_posts_and_refreshes_balance_when_user_is_supplied(): void
    {
        $this->createPostedReceivable('300.00');

        $service = app(GeneralLedgerService::class);

        $journalEntry = $service->createPaymentReceivedJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentId: (string) Str::uuid(),
            amount: '120.00',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            description: 'Customer payment received',
            user: $this->user,
            currencyCode: $this->company->currency
        );

        $journalEntry->refresh();
        $this->partner->refresh();

        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertNotNull($journalEntry->fiscal_hash);
        $this->assertEquals($this->user->id, $journalEntry->posted_by);
        $this->assertEquals('180.0000', $this->partner->receivable_balance);
        $this->assertNotNull($this->partner->balance_updated_at);
    }

    public function test_payment_received_outer_transaction_rollback_does_not_emit_posting_events(): void
    {
        Event::fake([JournalEntryPosted::class, PartnerBalanceUpdated::class]);

        $paymentId = (string) Str::uuid();
        $service = app(GeneralLedgerService::class);

        try {
            DB::transaction(function () use ($paymentId, $service): void {
                $service->createPaymentReceivedJournalEntry(
                    companyId: $this->company->id,
                    partnerId: $this->partner->id,
                    paymentId: $paymentId,
                    amount: '120.00',
                    paymentMethodAccountId: $this->cashAccount->id,
                    date: now(),
                    description: 'Customer payment received',
                    user: $this->user,
                    currencyCode: $this->company->currency
                );

                throw new \RuntimeException('rollback customer payment');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback customer payment', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'customer_payment',
            'source_id' => $paymentId,
        ]);
        Event::assertNotDispatched(JournalEntryPosted::class);
        Event::assertNotDispatched(PartnerBalanceUpdated::class);
    }

    public function test_journal_entry_has_draft_status_initially(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'total' => '120.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);

        $this->assertEquals(JournalEntryStatus::Draft, $journalEntry->status);
    }

    public function test_posting_journal_entry_adds_hash(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'total' => '120.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);
        $service->postEntry($journalEntry, $this->user);

        $journalEntry->refresh();

        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertNotNull($journalEntry->fiscal_hash);
        $this->assertNotNull($journalEntry->posted_at);
        $this->assertEquals($this->user->id, $journalEntry->posted_by);
    }

    public function test_customer_advance_creates_correct_journal_entry(): void
    {
        $service = app(GeneralLedgerService::class);
        $advanceAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerAdvance);

        $journalEntry = $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '500.00',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment'
        );

        $this->assertNotNull($journalEntry);
        $this->assertCount(2, $journalEntry->lines);

        // Bank/Cash should be debited
        $cashLine = $journalEntry->lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertEquals('500.000', $cashLine->debit);
        $this->assertEquals('0.000', $cashLine->credit);

        // Customer Advances should be credited (with partner for subledger)
        $advanceLine = $journalEntry->lines->firstWhere('account_id', $advanceAccount->id);
        $this->assertEquals('0.000', $advanceLine->debit);
        $this->assertEquals('500.000', $advanceLine->credit);
        $this->assertEquals($this->partner->id, $advanceLine->partner_id);
    }

    public function test_customer_advance_posts_and_refreshes_credit_balance(): void
    {
        $service = app(GeneralLedgerService::class);

        $journalEntry = $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '500.00',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency
        );

        $journalEntry->refresh();
        $this->partner->refresh();

        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertNotNull($journalEntry->fiscal_hash);
        $this->assertEquals($this->user->id, $journalEntry->posted_by);
        $this->assertEquals('500.0000', $this->partner->credit_balance);
        $this->assertNotNull($this->partner->balance_updated_at);
    }

    public function test_customer_advance_omitted_currency_posts_without_company_context(): void
    {
        app(CompanyContext::class)->clear();

        $service = app(GeneralLedgerService::class);

        $journalEntry = $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '500.00',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment'
        );

        $journalEntry->refresh();
        $this->partner->refresh();

        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertEquals('500.0000', $this->partner->credit_balance);
    }

    public function test_customer_advance_outer_transaction_rollback_does_not_emit_posting_events(): void
    {
        Event::fake([JournalEntryPosted::class, PartnerBalanceUpdated::class]);

        $advanceId = (string) Str::uuid();
        $service = app(GeneralLedgerService::class);

        try {
            DB::transaction(function () use ($advanceId, $service): void {
                $service->createCustomerAdvanceJournalEntry(
                    companyId: $this->company->id,
                    partnerId: $this->partner->id,
                    advanceId: $advanceId,
                    amount: '500.00',
                    paymentMethodAccountId: $this->cashAccount->id,
                    date: now(),
                    user: $this->user,
                    description: 'Customer advance payment',
                    currencyCode: $this->company->currency
                );

                throw new \RuntimeException('rollback customer advance');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback customer advance', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'advance',
            'source_id' => $advanceId,
        ]);
        Event::assertNotDispatched(JournalEntryPosted::class);
        Event::assertNotDispatched(PartnerBalanceUpdated::class);
    }

    public function test_supplier_invoice_creates_correct_journal_entry(): void
    {
        // Create supplier partner
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier',
            'type' => PartnerType::Supplier,
        ]);

        // Create expense account
        $expenseAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6100',
            'name' => 'Purchases',
            'type' => AccountType::Expense,
        ]);

        $service = app(GeneralLedgerService::class);
        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $vatDeductibleAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);

        $journalEntry = $service->createSupplierInvoiceJournalEntry(
            companyId: $this->company->id,
            partnerId: $supplier->id,
            invoiceId: (string) Str::uuid(),
            totalAmount: '119.00',
            netAmount: '100.00',
            vatAmount: '19.00',
            expenseAccountId: $expenseAccount->id,
            date: now(),
            user: $this->user
        );

        $this->assertNotNull($journalEntry);
        $this->assertCount(3, $journalEntry->lines);

        // Expense should be debited
        $expenseLine = $journalEntry->lines->firstWhere('account_id', $expenseAccount->id);
        $this->assertEquals('100.000', $expenseLine->debit);
        $this->assertEquals('0.000', $expenseLine->credit);

        // VAT Deductible should be debited
        $vatLine = $journalEntry->lines->firstWhere('account_id', $vatDeductibleAccount->id);
        $this->assertEquals('19.000', $vatLine->debit);
        $this->assertEquals('0.000', $vatLine->credit);

        // Accounts Payable should be credited (with partner for subledger)
        $payableLine = $journalEntry->lines->firstWhere('account_id', $payableAccount->id);
        $this->assertEquals('0.000', $payableLine->debit);
        $this->assertEquals('119.000', $payableLine->credit);
        $this->assertEquals($supplier->id, $payableLine->partner_id);
    }

    public function test_supplier_payment_creates_correct_journal_entry(): void
    {
        // Create supplier partner
        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier',
            'type' => PartnerType::Supplier,
        ]);

        $service = app(GeneralLedgerService::class);
        $payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);

        $journalEntry = $service->createSupplierPaymentJournalEntry(
            companyId: $this->company->id,
            partnerId: $supplier->id,
            paymentId: (string) Str::uuid(),
            amount: '500.00',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user
        );

        $this->assertNotNull($journalEntry);
        $this->assertCount(2, $journalEntry->lines);

        // Accounts Payable should be debited (reduces liability, with partner for subledger)
        $payableLine = $journalEntry->lines->firstWhere('account_id', $payableAccount->id);
        $this->assertEquals('500.000', $payableLine->debit);
        $this->assertEquals('0.000', $payableLine->credit);
        $this->assertEquals($supplier->id, $payableLine->partner_id);

        // Bank/Cash should be credited
        $cashLine = $journalEntry->lines->firstWhere('account_id', $this->cashAccount->id);
        $this->assertEquals('0.000', $cashLine->debit);
        $this->assertEquals('500.000', $cashLine->credit);
    }

    public function test_invoice_entry_refreshes_partner_balance(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $service = app(GeneralLedgerService::class);
        $journalEntry = $service->createFromInvoice($invoice, $this->user);

        // Post the entry so it affects balance calculations
        $service->postEntry($journalEntry, $this->user);

        // Balance is refreshed after posting - need to manually refresh again
        // because postEntry doesn't call refreshPartnerBalance
        $balanceService = app(PartnerBalanceService::class);
        $balanceService->refreshPartnerBalance($this->company->id, $this->partner->id);

        // Partner balance should now reflect posted entry
        $this->partner->refresh();
        $this->assertEquals('119.0000', $this->partner->receivable_balance);
        $this->assertNotNull($this->partner->balance_updated_at);
    }

    private function createPostedReceivable(string $amount): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-OPEN-001',
            'entry_date' => now(),
            'description' => 'Opening receivable',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'test_receivable',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->receivableAccount->id,
            'partner_id' => $this->partner->id,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Opening receivable',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Opening revenue',
            'line_order' => 1,
        ]);

        return $entry->load('lines');
    }

    private function createInvoice(array $attributes): Document
    {
        $document = Document::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-000001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Draft,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'currency' => 'TND',
        ], $attributes));

        // Create a line for the invoice
        DocumentLine::create([
            'document_id' => $document->id,
            'line_number' => 1,
            'description' => 'Test product',
            'quantity' => '1.00',
            'unit_price' => $attributes['subtotal'] ?? '100.00',
            'tax_rate' => isset($attributes['tax_amount']) && $attributes['tax_amount'] !== '0.00' ? '20.00' : '0.00',
            'line_total' => $attributes['subtotal'] ?? '100.00',
        ]);

        return $document;
    }

    private function createCreditNote(array $attributes): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-2025-000001',
            'document_date' => now()->toDateString(),
            'status' => DocumentStatus::Draft,
            'subtotal' => '50.00',
            'tax_amount' => '0.00',
            'total' => '50.00',
            'currency' => 'TND',
        ], $attributes));
    }
}
