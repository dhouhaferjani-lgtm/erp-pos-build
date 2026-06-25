<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
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
use App\Modules\Expense\Application\Services\ExpenseService;
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
        $this->assertEquals('180.000', $this->partner->receivable_balance);
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
            'total' => '100.00',
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

    public function test_posting_first_draft_entry_assigns_genesis_chain_sequence_and_verifies_chain(): void
    {
        $service = app(GeneralLedgerService::class);
        $journalEntry = $this->createBalancedDraftJournalEntry('JE-R2-GENESIS-001');

        $service->postEntry($journalEntry, $this->user, $this->company->currency);

        $journalEntry->refresh();

        $this->assertSame(1, $journalEntry->chain_sequence);
        $this->assertNull($journalEntry->previous_hash);
        $this->assertNotNull($journalEntry->fiscal_hash);
        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_post_entry_extends_existing_invoice_gl_hash_chain(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);

        app(AccountingService::class)->createInvoiceGLEntries($invoice);

        $invoiceEntry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'Document')
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        $manualEntry = $this->createBalancedDraftJournalEntry('JE-R2-MIXED-001');
        app(GeneralLedgerService::class)->postEntry($manualEntry, $this->user, $this->company->currency);
        $manualEntry->refresh();

        $this->assertSame(1, $invoiceEntry->chain_sequence);
        $this->assertSame(2, $manualEntry->chain_sequence);
        $this->assertSame($invoiceEntry->fiscal_hash, $manualEntry->previous_hash);
        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_cogs_posting_without_company_context_assigns_verifiable_chain_sequence(): void
    {
        app(CompanyContext::class)->clear();

        $entry = app(GeneralLedgerService::class)->createCOGSEntry(
            companyId: $this->company->id,
            invoiceId: (string) Str::uuid(),
            documentNumber: 'INV-R2-COGS-001',
            lineItems: [
                [
                    'product_id' => (string) Str::uuid(),
                    'quantity' => '2.0000',
                    'unit_cost' => '10.000000',
                ],
            ],
            date: now(),
            currencyCode: $this->company->currency,
        );

        $this->assertNotNull($entry);
        $entry->refresh();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame(1, $entry->chain_sequence);

        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_posting_without_currency_or_company_context_uses_company_currency_for_verifiable_chain(): void
    {
        app(CompanyContext::class)->clear();
        $journalEntry = $this->createBalancedDraftJournalEntry('JE-R2-NO-CONTEXT-001');

        app(GeneralLedgerService::class)->postEntry($journalEntry, $this->user);

        $journalEntry->refresh();

        $this->assertSame(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertSame(1, $journalEntry->chain_sequence);
        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_posting_with_non_company_currency_code_hashes_company_ledger_chain_verifiably(): void
    {
        $journalEntry = $this->createBalancedDraftJournalEntry('JE-R2-CURRENCY-MISMATCH-001');

        app(GeneralLedgerService::class)->postEntry($journalEntry, $this->user, 'EUR');

        $journalEntry->refresh();

        $this->assertSame(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertSame(1, $journalEntry->chain_sequence);
        $this->assertTrue(app(GeneralLedgerHashService::class)->verifyChain($this->company->id));
    }

    public function test_posting_unbalanced_journal_entry_is_rejected_without_mutation(): void
    {
        Event::fake([JournalEntryPosted::class]);

        $journalEntry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-UNBALANCED-001',
            'entry_date' => now(),
            'description' => 'Unbalanced draft',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '100.00',
            'credit' => '0',
            'description' => 'Debit side',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $journalEntry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0',
            'credit' => '90.00',
            'description' => 'Short credit side',
            'line_order' => 1,
        ]);

        try {
            app(GeneralLedgerService::class)->postEntry($journalEntry, $this->user);
            $this->fail('Unbalanced journal entries must not be posted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Cannot post unbalanced journal entry', $exception->getMessage());
        }

        $journalEntry->refresh();

        $this->assertEquals(JournalEntryStatus::Draft, $journalEntry->status);
        $this->assertNull($journalEntry->fiscal_hash);
        $this->assertNull($journalEntry->previous_hash);
        $this->assertNull($journalEntry->posted_at);
        $this->assertNull($journalEntry->posted_by);
        Event::assertNotDispatched(JournalEntryPosted::class);
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
        $this->assertEquals('500.000', $this->partner->credit_balance);
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
        $this->assertEquals('500.000', $this->partner->credit_balance);
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

    public function test_customer_advance_clearing_cannot_exceed_available_advance(): void
    {
        $service = app(GeneralLedgerService::class);

        $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency
        );

        try {
            $service->clearCustomerAdvanceToReceivable(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                invoiceId: (string) Str::uuid(),
                amount: '60.000',
                date: now(),
                description: 'Apply too much customer advance'
            );
            $this->fail('Customer advance clearing must not exceed the available advance balance.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Cannot clear customer advance beyond available balance', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'prepayment_application',
            'description' => 'Apply too much customer advance',
        ]);
    }

    public function test_customer_advance_clearing_requires_positive_amount(): void
    {
        $service = app(GeneralLedgerService::class);

        try {
            $service->clearCustomerAdvanceToReceivable(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                invoiceId: (string) Str::uuid(),
                amount: '0.000',
                date: now(),
                description: 'Apply zero customer advance'
            );
            $this->fail('Customer advance clearing must require a positive amount.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Customer advance clearing amount must be positive', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'prepayment_application',
            'description' => 'Apply zero customer advance',
        ]);
    }

    public function test_customer_advance_clearing_allows_available_advance_amount(): void
    {
        $service = app(GeneralLedgerService::class);
        $invoiceId = (string) Str::uuid();

        $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency
        );

        $journalEntry = $service->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: $invoiceId,
            amount: '40.000',
            date: now(),
            description: 'Apply customer advance'
        );

        $this->assertEquals(JournalEntryStatus::Draft, $journalEntry->status);
        $this->assertSame('prepayment_application', $journalEntry->source_type);
        $this->assertSame($invoiceId, $journalEntry->source_id);

        $advanceLine = $journalEntry->lines->firstWhere('account_id', Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerAdvance
        )->id);
        $receivableLine = $journalEntry->lines->firstWhere('account_id', $this->receivableAccount->id);

        $this->assertEquals('40.000', $advanceLine->debit);
        $this->assertEquals('0.000', $advanceLine->credit);
        $this->assertEquals($this->partner->id, $advanceLine->partner_id);
        $this->assertEquals('0.000', $receivableLine->debit);
        $this->assertEquals('40.000', $receivableLine->credit);
        $this->assertEquals($this->partner->id, $receivableLine->partner_id);
    }

    public function test_customer_advance_clearing_counts_existing_draft_clearings(): void
    {
        $service = app(GeneralLedgerService::class);

        $service->createCustomerAdvanceJournalEntry(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            advanceId: (string) Str::uuid(),
            amount: '40.000',
            paymentMethodAccountId: $this->cashAccount->id,
            date: now(),
            user: $this->user,
            description: 'Customer advance payment',
            currencyCode: $this->company->currency
        );

        $service->clearCustomerAdvanceToReceivable(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            invoiceId: (string) Str::uuid(),
            amount: '40.000',
            date: now(),
            description: 'Apply customer advance'
        );

        try {
            $service->clearCustomerAdvanceToReceivable(
                companyId: $this->company->id,
                partnerId: $this->partner->id,
                invoiceId: (string) Str::uuid(),
                amount: '1.000',
                date: now(),
                description: 'Apply duplicate customer advance'
            );
            $this->fail('Draft advance clearings must reduce available advance for later clearings.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Cannot clear customer advance beyond available balance', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'prepayment_application',
            'description' => 'Apply duplicate customer advance',
        ]);
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
        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertNotNull($journalEntry->fiscal_hash);
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
        $this->assertEquals(JournalEntryStatus::Posted, $journalEntry->status);
        $this->assertNotNull($journalEntry->fiscal_hash);
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

    public function test_posting_expense_posts_expense_journal_entry(): void
    {
        $expense = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'document_number' => 'EXP-DRAFT',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '0.000',
        ]);

        app(ExpenseService::class)->post($expense, $this->user);

        $entry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);
    }

    public function test_posting_partner_journal_entry_refreshes_partner_balance(): void
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

        $this->partner->refresh();
        $this->assertEquals('119.000', $this->partner->receivable_balance);
        $this->assertNotNull($this->partner->balance_updated_at);
    }

    public function test_draft_invoice_entry_creation_does_not_refresh_partner_balance(): void
    {
        $invoice = $this->createInvoice([
            'status' => DocumentStatus::Confirmed,
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        app(GeneralLedgerService::class)->createFromInvoice($invoice, $this->user);

        $this->partner->refresh();
        $this->assertEquals('0.000', $this->partner->receivable_balance);
        $this->assertNull($this->partner->balance_updated_at);
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

    private function createBalancedDraftJournalEntry(string $entryNumber): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => $entryNumber,
            'entry_date' => now(),
            'description' => 'R-2 hash chain regression',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '50.00',
            'credit' => '0',
            'description' => 'Debit side',
            'line_order' => 0,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0',
            'credit' => '50.00',
            'description' => 'Credit side',
            'line_order' => 1,
        ]);

        return $entry;
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
