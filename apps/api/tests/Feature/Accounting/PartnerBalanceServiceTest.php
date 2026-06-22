<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerBalanceService $service;

    private ChartOfAccountsService $chartService;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PartnerBalanceService::class);
        $this->chartService = app(ChartOfAccountsService::class);

        // Create a tenant for testing
        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company with chart of accounts
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company '.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->chartService->seedForCompany($this->company);

        // Create a customer
        $this->customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_partner_balance_is_zero_with_no_transactions(): void
    {
        $result = $this->service->getPartnerBalance(
            $this->company->id,
            $this->customer->id
        );

        $this->assertSame('0.000', $result['balance']);
        $this->assertSame(0, $result['transaction_count']);
    }

    public function test_invoice_increases_customer_receivable(): void
    {
        $this->createInvoiceEntry('1000.00');

        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );

        $this->assertSame('1000.000', $balance);
    }

    public function test_payment_reduces_customer_receivable(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');

        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );

        $this->assertSame('600.000', $balance);
    }

    public function test_multiple_invoices_and_payments_calculate_correctly(): void
    {
        // Two invoices: 1000 + 500 = 1500
        $this->createInvoiceEntry('1000.00');
        $this->createInvoiceEntry('500.00');

        // One payment: 700
        $this->createPaymentEntry('700.00');

        // Net balance: 1500 - 700 = 800
        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );

        $this->assertSame('800.000', $balance);
    }

    public function test_subledger_matches_control_account(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');

        $result = $this->service->reconcileSubledger(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );

        $this->assertTrue($result['is_balanced']);
        $this->assertSame('0.0000', $result['difference']);
    }

    public function test_subledger_detects_entries_without_partner(): void
    {
        // Create invoice entry with partner
        $this->createInvoiceEntry('1000.00');

        // Create a manual entry without partner (causes discrepancy)
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );
        $revenueAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::ProductRevenue
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-MANUAL-001',
            'entry_date' => now(),
            'description' => 'Manual adjustment without partner',
            'status' => JournalEntryStatus::Posted,
        ]);

        // No partner_id on receivable line
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => null, // Missing partner
            'debit' => '200.00',
            'credit' => '0.00',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => '0.00',
            'credit' => '200.00',
        ]);

        $result = $this->service->reconcileSubledger(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );

        // Subledger should show discrepancy
        $this->assertFalse($result['is_balanced']);
        $this->assertSame('200.0000', $result['difference']);
        $this->assertSame(1, $result['entries_without_partner']);
    }

    public function test_get_all_partner_balances(): void
    {
        // Create second customer
        $customer2 = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createInvoiceEntry('1000.00'); // customer 1
        $this->createInvoiceEntryForPartner($customer2->id, '500.00'); // customer 2

        $balances = $this->service->getAllPartnerBalances(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );

        $this->assertCount(2, $balances);
        $this->assertEquals('1500.0000', $balances->sum('balance'));
    }

    public function test_get_all_partner_balances_excludes_zero_balances(): void
    {
        // Create second customer
        $customer2 = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createInvoiceEntry('1000.00'); // customer 1
        $this->createInvoiceEntryForPartner($customer2->id, '500.00'); // customer 2
        $this->createPaymentEntryForPartner($customer2->id, '500.00'); // customer 2 fully paid

        $balances = $this->service->getAllPartnerBalances(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable,
            excludeZeroBalances: true
        );

        // Only customer 1 should have balance
        $this->assertCount(1, $balances);
    }

    public function test_partner_statement_shows_transactions(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');

        $statement = $this->service->getPartnerStatement(
            $this->company->id,
            $this->customer->id,
            SystemAccountPurpose::CustomerReceivable
        );

        $this->assertCount(2, $statement);

        // First transaction: invoice (debit 1000)
        $first = $statement->first();
        $this->assertEquals(1000, (float) $first->debit);
        $this->assertSame('1000.0000', $first->running_balance);

        // Second transaction: payment (credit 400)
        $last = $statement->last();
        $this->assertEquals(400, (float) $last->credit);
        $this->assertSame('600.0000', $last->running_balance);
    }

    public function test_partner_statement_filters_by_date(): void
    {
        // Create entries on different dates
        $this->createInvoiceEntryOnDate('1000.00', now()->subDays(10));
        $this->createInvoiceEntryOnDate('500.00', now()->subDays(5));
        $this->createPaymentEntryOnDate('300.00', now()->subDays(2));

        // Only get entries from last 7 days
        $statement = $this->service->getPartnerStatement(
            $this->company->id,
            $this->customer->id,
            SystemAccountPurpose::CustomerReceivable,
            fromDate: now()->subDays(7)->toDateString()
        );

        // Should only have 2 transactions (last 7 days)
        $this->assertCount(2, $statement);
    }

    public function test_refresh_partner_balance_updates_cached_values(): void
    {
        // Create invoice
        $this->createInvoiceEntry('1000.00');

        // Refresh balance
        $this->service->refreshPartnerBalance($this->company->id, $this->customer->id);

        // Check cached values
        $this->customer->refresh();
        $this->assertSame('1000.000', $this->customer->receivable_balance);
        $this->assertNotNull($this->customer->balance_updated_at);
    }

    public function test_credit_balance_is_stored_as_positive_magnitude(): void
    {
        // A 50.00 customer advance CREDITS the Customer Advances liability with
        // the partner attached, so the raw subledger sign is debit - credit = -50.
        // The denormalized cache must store the MAGNITUDE (50) — the convention
        // every reader (net_balance, POS creditRulesEngine) and the non-negative
        // fiscal balance-snapshot payload assume.
        $this->createCustomerAdvanceEntry('50.00');

        $this->service->refreshPartnerBalance($this->company->id, $this->customer->id);

        $this->customer->refresh();
        $this->assertSame('50.000', $this->customer->credit_balance);
    }

    public function test_net_balance_subtracts_advance_credit_from_receivable(): void
    {
        // They owe us 100 on an invoice and hold a 30 advance credit.
        $this->createInvoiceEntry('100.00');
        $this->createCustomerAdvanceEntry('30.00');

        $this->service->refreshPartnerBalance($this->company->id, $this->customer->id);

        $this->customer->refresh();
        $this->assertSame('100.000', $this->customer->receivable_balance);
        $this->assertSame('30.000', $this->customer->credit_balance);
        // net = receivable - credit = 70 (NOT 130, which the signed-negative bug produced).
        $this->assertSame('70.000', $this->customer->net_balance);
    }

    public function test_credit_balance_clamps_to_zero_when_advance_overcleared(): void
    {
        // 40 credited as advance, then 60 cleared back to receivable (debit side):
        // raw subledger = 40 - 60 = -20 (a net-debit advance — an anomaly). The
        // cache stores a non-negative magnitude, so it clamps to 0 rather than
        // caching a negative "credit".
        $this->createCustomerAdvanceEntry('40.00');
        $this->clearCustomerAdvanceEntry('60.00');

        $this->service->refreshPartnerBalance($this->company->id, $this->customer->id);

        $this->customer->refresh();
        $this->assertSame('0.000', $this->customer->credit_balance);
    }

    public function test_payable_balance_is_stored_as_positive_magnitude(): void
    {
        // A supplier invoice CREDITS Accounts Payable (a liability) with the
        // supplier attached, so the raw subledger sign is debit - credit = -80.
        // The cache must store the MAGNITUDE (80) — net_balance for a supplier
        // returns payable_balance directly and the total_payable aggregate sums it,
        // both assuming a non-negative magnitude.
        $supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->createSupplierPayableEntry($supplier->id, '80.00');

        $this->service->refreshPartnerBalance($this->company->id, $supplier->id);

        $supplier->refresh();
        $this->assertSame('80.000', $supplier->payable_balance);
        $this->assertSame('80.000', $supplier->net_balance);
    }

    public function test_get_cached_or_calculate_balance_returns_cached_values(): void
    {
        $this->createInvoiceEntry('1000.00');

        // First call should calculate and cache
        $result1 = $this->service->getCachedOrCalculateBalance(
            $this->company->id,
            $this->customer->id,
            refreshIfStale: true,
            staleMinutes: 60
        );

        $this->assertSame('1000.000', $result1['receivable_balance']);
        $this->assertTrue($result1['is_from_cache']);
        $this->assertNotNull($result1['balance_updated_at']);
    }

    public function test_refresh_all_partner_balances(): void
    {
        // Create second customer
        $customer2 = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->createInvoiceEntry('1000.00');
        $this->createInvoiceEntryForPartner($customer2->id, '500.00');

        // Refresh all
        $count = $this->service->refreshAllPartnerBalances($this->company->id);

        $this->assertSame(2, $count);

        // Check both customers have updated balances
        $this->customer->refresh();
        $customer2->refresh();

        $this->assertSame('1000.000', $this->customer->receivable_balance);
        $this->assertSame('500.000', $customer2->receivable_balance);
    }

    public function test_only_posted_entries_affect_balance(): void
    {
        // Create posted invoice
        $this->createInvoiceEntry('1000.00');

        // Create draft invoice (should not affect balance)
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );
        $revenueAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::ProductRevenue
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-DRAFT-001',
            'entry_date' => now(),
            'description' => 'Draft invoice',
            'status' => JournalEntryStatus::Draft, // Not posted
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $this->customer->id,
            'debit' => '500.00',
            'credit' => '0.00',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => '0.00',
            'credit' => '500.00',
        ]);

        // Balance should only show the posted entry (1000)
        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );

        $this->assertSame('1000.000', $balance);
    }

    // ========== Helper Methods ==========

    private function createInvoiceEntry(string $amount): void
    {
        $this->createInvoiceEntryForPartner($this->customer->id, $amount);
    }

    private function createInvoiceEntryForPartner(string $partnerId, string $amount): void
    {
        $this->createInvoiceEntryForPartnerOnDate($partnerId, $amount, now());
    }

    private function createInvoiceEntryOnDate(string $amount, \DateTimeInterface $date): void
    {
        $this->createInvoiceEntryForPartnerOnDate($this->customer->id, $amount, $date);
    }

    private function createInvoiceEntryForPartnerOnDate(
        string $partnerId,
        string $amount,
        \DateTimeInterface $date
    ): void {
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );
        $revenueAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::ProductRevenue
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'INV-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Test invoice',
            'status' => JournalEntryStatus::Posted,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => $amount,
            'credit' => '0.00',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }

    private function createCustomerAdvanceEntry(string $amount): void
    {
        $advanceAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerAdvance
        );
        $bankAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::Bank
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'ADV-'.uniqid(),
            'entry_date' => now(),
            'description' => 'Customer advance received',
            'status' => JournalEntryStatus::Posted,
        ]);

        // Debit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $bankAccount->id,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0.00',
        ]);

        // Credit: Customer Advances (liability - with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $advanceAccount->id,
            'partner_id' => $this->customer->id,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }

    private function clearCustomerAdvanceEntry(string $amount): void
    {
        $advanceAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerAdvance
        );
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'ADVCLR-'.uniqid(),
            'entry_date' => now(),
            'description' => 'Clear customer advance to receivable',
            'status' => JournalEntryStatus::Posted,
        ]);

        // Debit: Customer Advances (clear the liability) - with partner
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $advanceAccount->id,
            'partner_id' => $this->customer->id,
            'debit' => $amount,
            'credit' => '0.00',
        ]);

        // Credit: Customer Receivable - with partner
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $this->customer->id,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }

    private function createSupplierPayableEntry(string $partnerId, string $amount): void
    {
        $payableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::SupplierPayable
        );
        $bankAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::Bank
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'SINV-'.uniqid(),
            'entry_date' => now(),
            'description' => 'Supplier invoice',
            'status' => JournalEntryStatus::Posted,
        ]);

        // Debit: Bank/expense side (no partner)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $bankAccount->id,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0.00',
        ]);

        // Credit: Accounts Payable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }

    private function createPaymentEntry(string $amount): void
    {
        $this->createPaymentEntryForPartner($this->customer->id, $amount);
    }

    private function createPaymentEntryForPartner(string $partnerId, string $amount): void
    {
        $this->createPaymentEntryForPartnerOnDate($partnerId, $amount, now());
    }

    private function createPaymentEntryOnDate(string $amount, \DateTimeInterface $date): void
    {
        $this->createPaymentEntryForPartnerOnDate($this->customer->id, $amount, $date);
    }

    private function createPaymentEntryForPartnerOnDate(
        string $partnerId,
        string $amount,
        \DateTimeInterface $date
    ): void {
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CustomerReceivable
        );
        $bankAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::Bank
        );

        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'PMT-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Test payment',
            'status' => JournalEntryStatus::Posted,
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $bankAccount->id,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0.00',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }
}
