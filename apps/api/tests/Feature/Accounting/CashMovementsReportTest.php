<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CashMovementsReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    private Account $cashAccount;

    private Account $bankAccount;

    private Account $revenueAccount;

    private PaymentRepository $cashRepository;

    private PaymentRepository $bankRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Cash Movements Tenant',
            'slug' => 'cash-movements-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cash Movements Company',
            'legal_name' => 'Cash Movements Company LLC',
            'tax_id' => 'CM123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reports User',
            'email' => 'reports-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['reports.operational']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashAccount = $this->account('1100', 'Cash', AccountType::Asset);
        $this->bankAccount = $this->account('1200', 'Bank', AccountType::Asset);
        $this->revenueAccount = $this->account('7000', 'Sales Revenue', AccountType::Revenue);
        $this->account('6000', 'Operating Expense', AccountType::Expense);

        $this->cashRepository = $this->repository('CASH', 'Main Cash', RepositoryType::CashRegister, $this->cashAccount);
        $this->bankRepository = $this->repository('BANK', 'Main Bank', RepositoryType::BankAccount, $this->bankAccount);

        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Counterparty SARL',
            'type' => PartnerType::Both,
            'code' => 'CP-001',
        ]);
    }

    public function test_cash_movements_report_returns_payments_and_posted_cash_journal_lines(): void
    {
        $incomingPayment = $this->payment(
            repository: $this->cashRepository,
            amount: '123.450',
            paymentDate: '2026-06-30',
            paymentType: PaymentType::DocumentPayment,
        );

        $this->payment(
            repository: $this->bankRepository,
            amount: '999.000',
            paymentDate: '2026-06-30',
            paymentType: PaymentType::DocumentPayment,
        );

        $journalEntry = $this->journalEntry('2026-07-01', 'manual_cash_sale', Str::uuid()->toString());
        $this->journalLine($journalEntry, $this->cashAccount, '75.000', '0.000', 'Cash sale');
        $this->journalLine($journalEntry, $this->revenueAccount, '0.000', '75.000', 'Revenue');

        $this->journalLine(
            $this->journalEntry('2026-06-29', 'draft_cash_sale', Str::uuid()->toString(), JournalEntryStatus::Draft),
            $this->cashAccount,
            '88.000',
            '0.000',
            'Draft cash sale',
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-06-30&to=2026-07-01&repository_id={$this->cashRepository->id}");

        $response->assertOk();
        $response->assertJsonPath('data.0.date', '2026-07-01');
        $response->assertJsonPath('data.0.direction', 'in');
        $response->assertJsonPath('data.0.amount', '75.00');
        $response->assertJsonPath('data.0.currency', 'EUR');
        $response->assertJsonPath('data.0.source_type', 'manual_cash_sale');
        $response->assertJsonPath('data.0.counterparty', 'Cash sale');
        $response->assertJsonPath('data.0.gl_account', '1100');
        $response->assertJsonPath('data.1.date', '2026-06-30');
        $response->assertJsonPath('data.1.direction', 'in');
        $response->assertJsonPath('data.1.amount', '123.45');
        $response->assertJsonPath('data.1.source_type', 'payment');
        $response->assertJsonPath('data.1.source_id', $incomingPayment->id);
        $response->assertJsonPath('data.1.counterparty', 'Counterparty SARL');
        $response->assertJsonPath('data.1.gl_account', '1100');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 50);
    }

    public function test_cash_movements_report_deduplicates_payment_backed_journal_lines(): void
    {
        $this->assertPaymentBackedJournalLineIsDeduplicated(
            sourceType: 'customer_payment',
            paymentType: PaymentType::DocumentPayment,
            cashDebit: '42.000',
            cashCredit: '0.000',
            expectedDirection: 'in',
            expectedAmount: '42.00',
        );
    }

    public function test_cash_movements_report_deduplicates_supplier_payment_journal_lines(): void
    {
        $this->assertPaymentBackedJournalLineIsDeduplicated(
            sourceType: 'supplier_payment',
            paymentType: PaymentType::SupplierPayment,
            cashDebit: '0.000',
            cashCredit: '55.000',
            expectedDirection: 'out',
            expectedAmount: '55.00',
        );
    }

    public function test_cash_movements_report_deduplicates_advance_journal_lines(): void
    {
        $this->assertPaymentBackedJournalLineIsDeduplicated(
            sourceType: 'advance',
            paymentType: PaymentType::Advance,
            cashDebit: '67.000',
            cashCredit: '0.000',
            expectedDirection: 'in',
            expectedAmount: '67.00',
        );
    }

    public function test_cash_movements_report_deduplicates_supplier_advance_refund_and_reports_it_as_cash_in(): void
    {
        $this->assertPaymentBackedJournalLineIsDeduplicated(
            sourceType: 'supplier_advance_refund',
            paymentType: PaymentType::Refund,
            cashDebit: '79.000',
            cashCredit: '0.000',
            expectedDirection: 'in',
            expectedAmount: '79.00',
        );
    }

    public function test_cash_movements_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/cash-movements?from=2026-07-02&to=2026-07-02');

        $response->assertUnauthorized();
    }

    public function test_cash_movements_report_requires_reports_operational_permission(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Reports User',
            'email' => 'no-reports-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-02&to=2026-07-02');

        $response->assertForbidden();
    }

    public function test_cash_movements_report_excludes_other_company_payments_and_journal_lines(): void
    {
        $ownPayment = $this->payment(
            repository: $this->cashRepository,
            amount: '42.000',
            paymentDate: '2026-07-02',
            paymentType: PaymentType::DocumentPayment,
        );

        $ownEntry = $this->journalEntry('2026-07-02', 'manual_cash_sale', Str::uuid()->toString());
        $this->journalLine($ownEntry, $this->cashAccount, '24.000', '0.000', 'Own cash sale');
        $this->journalLine($ownEntry, $this->revenueAccount, '0.000', '24.000', 'Own revenue');

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Cash Movements Company',
            'legal_name' => 'Other Cash Movements Company LLC',
            'tax_id' => 'CM999',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $otherCashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'code' => '1100',
            'name' => 'Other Cash',
            'type' => AccountType::Asset,
        ]);

        $otherRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'code' => '7000',
            'name' => 'Other Sales Revenue',
            'type' => AccountType::Revenue,
        ]);

        $otherRepository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'OTHER-CASH',
            'name' => 'Other Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.000',
            'gl_account_id' => $otherCashAccount->id,
            'is_active' => true,
        ]);

        $otherPartner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Counterparty SARL',
            'type' => PartnerType::Both,
            'code' => 'CP-999',
        ]);

        $otherPayment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $otherRepository->id,
            'amount' => '99.000',
            'currency' => 'EUR',
            'payment_date' => '2026-07-02',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::WebAdmin,
            'reference' => 'PAY-'.Str::uuid()->toString(),
        ]);

        $otherEntry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'entry_number' => 'JE-'.Str::uuid()->toString(),
            'entry_date' => '2026-07-02',
            'description' => 'Other cash movement test entry',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'other_manual_cash_sale',
            'source_id' => Str::uuid()->toString(),
            'posted_at' => now(),
        ]);
        JournalLine::create([
            'journal_entry_id' => $otherEntry->id,
            'account_id' => $otherCashAccount->id,
            'debit' => '88.000',
            'credit' => '0.000',
            'description' => 'Other cash sale',
            'line_order' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $otherEntry->id,
            'account_id' => $otherRevenueAccount->id,
            'debit' => '0.000',
            'credit' => '88.000',
            'description' => 'Other revenue',
            'line_order' => 1,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-02&to=2026-07-02');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $response->assertJsonFragment(['source_id' => $ownPayment->id]);
        $response->assertJsonFragment(['source_id' => $ownEntry->source_id]);
        $response->assertJsonMissing(['source_id' => $otherPayment->id]);
        $response->assertJsonMissing(['source_id' => $otherEntry->source_id]);
    }

    private function assertPaymentBackedJournalLineIsDeduplicated(
        string $sourceType,
        PaymentType $paymentType,
        string $cashDebit,
        string $cashCredit,
        string $expectedDirection,
        string $expectedAmount,
    ): void {
        $payment = $this->payment(
            repository: $this->cashRepository,
            amount: $expectedAmount.'0',
            paymentDate: '2026-07-02',
            paymentType: $paymentType,
        );

        $entry = $this->journalEntry('2026-07-02', $sourceType, $payment->id);
        $this->journalLine($entry, $this->cashAccount, $cashDebit, $cashCredit, 'Duplicated payment cash line');
        $this->journalLine($entry, $this->revenueAccount, $cashCredit, $cashDebit, 'Offset');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-02&to=2026-07-02');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_type', 'payment');
        $response->assertJsonPath('data.0.source_id', $payment->id);
        $response->assertJsonPath('data.0.direction', $expectedDirection);
        $response->assertJsonPath('data.0.amount', $expectedAmount);
    }

    public function test_cash_movements_report_deduplicates_pos_receipt_journal_lines_represented_by_payments(): void
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Shop',
            'code' => 'SHOP',
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'code' => 'POS01',
        ]);

        $fiscalEventId = $this->fiscalEvent($terminal, '2026-07-03');
        $receipt = Receipt::factory()
            ->for($this->tenant, 'tenant')
            ->for($this->company, 'company')
            ->for($location, 'location')
            ->for($terminal, 'terminal')
            ->for($this->user, 'cashier')
            ->withTotal('64.000', '0.000')
            ->create([
                'receipt_number' => 'POS-2026-0001',
                'receipt_type' => ReceiptType::Sale,
                'fiscal_status' => FiscalStatus::Fiscalized,
                'fiscal_event_id' => $fiscalEventId,
                'posted_at' => '2026-07-03 10:00:00',
                'partner_id' => $this->partner->id,
                'currency' => 'EUR',
            ]);

        $payment = $this->payment(
            repository: $this->cashRepository,
            amount: '64.000',
            paymentDate: '2026-07-03',
            paymentType: PaymentType::POS,
            origin: PaymentOrigin::Pos,
            fiscalEventId: $fiscalEventId,
        );

        $entry = $this->journalEntry('2026-07-03', 'pos_receipt', $receipt->id);
        $this->journalLine($entry, $this->cashAccount, '64.000', '0.000', 'POS cash line');
        $this->journalLine($entry, $this->revenueAccount, '0.000', '64.000', 'POS revenue');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-03&to=2026-07-03');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_type', 'payment');
        $response->assertJsonPath('data.0.source_id', $payment->id);
        $response->assertJsonPath('data.0.amount', '64.00');
    }

    public function test_cash_movements_report_counts_pos_refund_once_as_a_single_outflow(): void
    {
        // Reproduces exactly what TreasuryReceiptBridge writes for a POS refund
        // (SALE_RECEIPT with invoice_type_code=REFUND): a POS Payment leg
        // (payment_type=POS, origin=Pos, fiscal_event_id set) whose
        // journal_entry_id points at a `pos_receipt_refund` GL entry whose cash
        // line CREDITS the drawer (money out). The at-rest money is correct; the
        // report must surface EXACTLY ONE cash-movement row, direction Out.
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Refund Shop',
            'code' => 'RSHOP',
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'code' => 'POS02',
        ]);

        // The original sale receipt the refund reverses (satisfies the
        // pos_receipts_return_logic check: a Return must reference an original).
        $originalEventId = $this->fiscalEvent($terminal, '2026-07-04');
        $originalReceipt = Receipt::factory()
            ->for($this->tenant, 'tenant')
            ->for($this->company, 'company')
            ->for($location, 'location')
            ->for($terminal, 'terminal')
            ->for($this->user, 'cashier')
            ->withTotal('30.000', '0.000')
            ->create([
                'receipt_number' => 'POS-2026-0002',
                'receipt_type' => ReceiptType::Sale,
                'fiscal_status' => FiscalStatus::Fiscalized,
                'fiscal_event_id' => $originalEventId,
                'posted_at' => '2026-07-04 09:00:00',
                'partner_id' => $this->partner->id,
                'currency' => 'EUR',
            ]);

        $fiscalEventId = $this->fiscalEvent($terminal, '2026-07-04');
        $receipt = Receipt::factory()
            ->for($this->tenant, 'tenant')
            ->for($this->company, 'company')
            ->for($location, 'location')
            ->for($terminal, 'terminal')
            ->for($this->user, 'cashier')
            ->withTotal('30.000', '0.000')
            ->create([
                'receipt_number' => 'POS-2026-0003',
                'receipt_type' => ReceiptType::Return,
                'original_receipt_id' => $originalReceipt->id,
                'return_reason' => ReturnReason::CustomerChangedMind,
                'fiscal_status' => FiscalStatus::Fiscalized,
                'fiscal_event_id' => $fiscalEventId,
                'posted_at' => '2026-07-04 10:00:00',
                'partner_id' => $this->partner->id,
                'currency' => 'EUR',
            ]);

        // The refund GL entry — cash CREDITED (out), revenue DEBITED — the
        // inverse of a sale receipt. source_type='pos_receipt_refund',
        // source_id=receipt->id (NOT payment->id).
        $refundEntry = $this->journalEntry('2026-07-04', 'pos_receipt_refund', $receipt->id);
        $this->journalLine($refundEntry, $this->cashAccount, '0.000', '30.000', 'POS refund cash out');
        $this->journalLine($refundEntry, $this->revenueAccount, '30.000', '0.000', 'POS refund revenue reversal');

        // The refund tender leg — a POS Payment linked to the refund GL entry.
        $payment = $this->payment(
            repository: $this->cashRepository,
            amount: '30.000',
            paymentDate: '2026-07-04',
            paymentType: PaymentType::POS,
            origin: PaymentOrigin::Pos,
            fiscalEventId: $fiscalEventId,
        );
        $payment->update(['journal_entry_id' => $refundEntry->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-04&to=2026-07-04');

        $response->assertOk();
        // Exactly ONE row — no phantom inflow from the payments side, no
        // duplicate outflow from the GL line.
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_type', 'payment');
        $response->assertJsonPath('data.0.source_id', $payment->id);
        $response->assertJsonPath('data.0.direction', 'out');
        $response->assertJsonPath('data.0.amount', '30.00');
    }

    public function test_cash_movements_report_validates_date_filters(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-99-99&to=2026-07-01');

        $response->assertUnprocessable();
        $response->assertJsonPath('error.errors.from.0', 'The from field must match the format Y-m-d.');
    }

    public function test_direction_filter_restricts_rows_and_pagination_meta(): void
    {
        $incomingPayment = $this->payment(
            repository: $this->cashRepository,
            amount: '25.000',
            paymentDate: '2026-07-05',
            paymentType: PaymentType::DocumentPayment,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '10.000',
            paymentDate: '2026-07-05',
            paymentType: PaymentType::SupplierPayment,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-05&to=2026-07-05&direction=in&per_page=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_id', $incomingPayment->id);
        $response->assertJsonPath('data.0.direction', 'in');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.last_page', 1);
        $response->assertJsonPath('meta.from', 1);
        $response->assertJsonPath('meta.to', 1);
    }

    public function test_totals_are_grouped_per_currency_across_payment_and_journal_sources(): void
    {
        $this->company->update(['currency' => 'TND']);

        $this->payment(
            repository: $this->cashRepository,
            amount: '100.125',
            paymentDate: '2026-07-06',
            paymentType: PaymentType::DocumentPayment,
            currency: 'TND',
        );
        $this->payment(
            repository: $this->bankRepository,
            amount: '20.500',
            paymentDate: '2026-07-06',
            paymentType: PaymentType::SupplierPayment,
            currency: 'EUR',
        );

        $journalEntry = $this->journalEntry('2026-07-06', 'manual_cash_expense', Str::uuid()->toString());
        $this->journalLine($journalEntry, $this->cashAccount, '0.000', '40.005', 'Cash expense');
        $this->journalLine($journalEntry, $this->revenueAccount, '40.005', '0.000', 'Offset');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-06&to=2026-07-06');

        $response->assertOk();
        $response->assertJsonPath('meta.totals.TND.in', '100.125');
        $response->assertJsonPath('meta.totals.TND.out', '40.005');
        $response->assertJsonPath('meta.totals.TND.net', '60.120');
        $response->assertJsonPath('meta.totals.EUR.in', '0.00');
        $response->assertJsonPath('meta.totals.EUR.out', '20.50');
        $response->assertJsonPath('meta.totals.EUR.net', '-20.50');
    }

    public function test_totals_cover_the_whole_filtered_range_not_the_page(): void
    {
        $this->payment(
            repository: $this->cashRepository,
            amount: '15.000',
            paymentDate: '2026-07-07',
            paymentType: PaymentType::DocumentPayment,
        );
        $this->payment(
            repository: $this->bankRepository,
            amount: '35.000',
            paymentDate: '2026-07-07',
            paymentType: PaymentType::DocumentPayment,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '9.000',
            paymentDate: '2026-07-07',
            paymentType: PaymentType::SupplierPayment,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-07&to=2026-07-07&direction=in&per_page=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.totals.EUR.in', '50.00');
        $response->assertJsonPath('meta.totals.EUR.out', '0.00');
        $response->assertJsonPath('meta.totals.EUR.net', '50.00');
    }

    // ── W-7 F-3: multi-branch cash visibility (fix lane L3) ────────────────
    // The scope contract is the one the aged-* reports already implement via
    // ReportsController::reportLocationScope(): an unscoped read is CLAMPED to
    // the principal's grant, an explicitly requested id OUTSIDE that grant is
    // REFUSED (403), and a grant covering every active location degrades to the
    // unrestricted read so NULL-location rows stay visible.

    public function test_location_ids_scopes_cash_movements_to_the_requested_branch(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $atShopA = $this->payment(
            repository: $this->cashRepository,
            amount: '10.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopA->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '20.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopB->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '5.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20&location_ids[]={$shopA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_id', $atShopA->id);
        $response->assertJsonPath('data.0.amount', '10.00');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.totals.EUR.in', '10.00');
        $response->assertJsonPath('meta.totals.EUR.net', '10.00');
    }

    public function test_unscoped_cash_movements_read_keeps_every_branch_and_unattributed_cash(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $this->payment(
            repository: $this->cashRepository,
            amount: '10.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopA->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '20.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopB->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '5.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.totals.EUR.in', '35.00');
    }

    public function test_a_location_scope_naming_every_active_location_still_shows_unattributed_cash(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $this->payment(
            repository: $this->cashRepository,
            amount: '10.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopA->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '5.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson(
                '/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20'
                ."&location_ids[]={$shopA->id}&location_ids[]={$shopB->id}"
            );

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.totals.EUR.in', '15.00');
    }

    public function test_a_location_outside_the_principal_grant_is_refused(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => json_encode([$shopA->id], JSON_THROW_ON_ERROR)]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20&location_ids[]={$shopB->id}");

        $response->assertForbidden();
    }

    public function test_an_unscoped_read_is_clamped_to_the_principal_grant(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $atShopA = $this->payment(
            repository: $this->cashRepository,
            amount: '10.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopA->id,
        );
        $this->payment(
            repository: $this->cashRepository,
            amount: '20.000',
            paymentDate: '2026-07-20',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopB->id,
        );

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => json_encode([$shopA->id], JSON_THROW_ON_ERROR)]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_id', $atShopA->id);
        $response->assertJsonPath('meta.totals.EUR.in', '10.00');
    }

    public function test_journal_only_cash_lines_are_scoped_by_the_owning_repository_location(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $this->cashRepository->update(['location_id' => $shopA->id]);
        $this->bankRepository->update(['location_id' => $shopB->id]);

        $cashEntry = $this->journalEntry('2026-07-21', 'manual_cash_sale', Str::uuid()->toString());
        $this->journalLine($cashEntry, $this->cashAccount, '30.000', '0.000', 'Shop A cash sale');
        $this->journalLine($cashEntry, $this->revenueAccount, '0.000', '30.000', 'Revenue');

        $bankEntry = $this->journalEntry('2026-07-21', 'manual_bank_sale', Str::uuid()->toString());
        $this->journalLine($bankEntry, $this->bankAccount, '40.000', '0.000', 'Shop B bank sale');
        $this->journalLine($bankEntry, $this->revenueAccount, '0.000', '40.000', 'Revenue');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-21&to=2026-07-21&location_ids[]={$shopA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_type', 'manual_cash_sale');
        $response->assertJsonPath('data.0.amount', '30.00');
        $response->assertJsonPath('meta.totals.EUR.in', '30.00');
    }

    public function test_a_payment_backed_journal_line_is_not_resurrected_by_a_location_scope(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        // The register lives at Shop A; the payment itself is attributed to the
        // Shop B document it settles. Under a Shop-A scope the payment row drops
        // out — its GL twin must NOT take its place, or the same cash move would
        // be reported under a branch that already excluded it.
        $this->cashRepository->update(['location_id' => $shopA->id]);

        $payment = $this->payment(
            repository: $this->cashRepository,
            amount: '12.000',
            paymentDate: '2026-07-22',
            paymentType: PaymentType::DocumentPayment,
            locationId: $shopB->id,
        );

        $entry = $this->journalEntry('2026-07-22', 'customer_payment', $payment->id);
        $this->journalLine($entry, $this->cashAccount, '12.000', '0.000', 'Duplicated payment cash line');
        $this->journalLine($entry, $this->revenueAccount, '0.000', '12.000', 'Offset');

        $scopedToA = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-22&to=2026-07-22&location_ids[]={$shopA->id}");

        $scopedToA->assertOk();
        $scopedToA->assertJsonCount(0, 'data');

        $scopedToB = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-22&to=2026-07-22&location_ids[]={$shopB->id}");

        $scopedToB->assertOk();
        $scopedToB->assertJsonCount(1, 'data');
        $scopedToB->assertJsonPath('data.0.source_type', 'payment');
        $scopedToB->assertJsonPath('data.0.source_id', $payment->id);
    }

    /**
     * Authz gate 2026-08-06, CRITICAL. `payment_repositories.gl_account_id` is
     * many-to-one, and BOTH provisioning paths — the backfill migration
     * `2026_03_02_400000` and `PaymentRepositorySeeder` — assign the single
     * company-wide `SystemAccountPurpose::Cash` account to EVERY cash_register
     * and safe. On that default shape a company-cash journal line (a petty-cash
     * expense settlement: `source_type = 'expense_settlement'`, absent from
     * PAYMENT_BACKED_SOURCE_TYPES, so the journal leg emits it) matched an
     * in-scope repository for every branch and was reported IN FULL under all
     * four — a 4x overstatement that broke both invariants MTP-MLC-08 asserts.
     *
     * The fixture is tenant #1's exact shape: one company, four branches, one
     * shared cash GL account, per-branch POS-style cash in, one company-level
     * cash out.
     */
    public function test_a_cash_journal_line_on_a_gl_account_shared_across_branches_is_unattributed_not_multiplied(): void
    {
        $branches = [];
        foreach (['A', 'B', 'C', 'D'] as $suffix) {
            $branches[$suffix] = $this->location('SHOP-'.$suffix, 'Shop '.$suffix);
        }

        // Default provisioning: every register on the SAME company-wide Cash
        // account. The pre-existing CASH register becomes Shop A's.
        $this->cashRepository->update(['location_id' => $branches['A']->id]);
        foreach (['B', 'C', 'D'] as $suffix) {
            $this->repository(
                'CASH-'.$suffix,
                'Register '.$suffix,
                RepositoryType::CashRegister,
                $this->cashAccount,
            )->update(['location_id' => $branches[$suffix]->id]);
        }

        $branchPayments = [];
        foreach (['A' => '1.000', 'B' => '2.000', 'C' => '4.000', 'D' => '8.000'] as $suffix => $amount) {
            $branchPayments[$suffix] = $this->payment(
                repository: $this->cashRepository,
                amount: $amount,
                paymentDate: '2026-07-23',
                paymentType: PaymentType::DocumentPayment,
                locationId: $branches[$suffix]->id,
            );
        }

        // The company-level petty-cash settlement: no payment row backs it, and
        // no branch owns it — four registers answer to its GL account.
        $settlement = $this->journalEntry('2026-07-23', 'expense_settlement', Str::uuid()->toString());
        $this->journalLine($settlement, $this->cashAccount, '0.000', '25.000', 'Petty cash expense');
        $this->journalLine($settlement, $this->revenueAccount, '25.000', '0.000', 'Offset');

        $unscoped = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-23&to=2026-07-23');

        $unscoped->assertOk();
        $unscoped->assertJsonCount(5, 'data');
        $unscoped->assertJsonPath('meta.totals.EUR.in', '15.00');
        $unscoped->assertJsonPath('meta.totals.EUR.out', '25.00');

        $seenSourceIds = [];
        foreach (['A' => '1.00', 'B' => '2.00', 'C' => '4.00', 'D' => '8.00'] as $suffix => $expected) {
            $scoped = $this->actingAs($this->user, 'sanctum')
                ->getJson(
                    '/api/v1/reports/cash-movements?from=2026-07-23&to=2026-07-23'
                    ."&location_ids[]={$branches[$suffix]->id}"
                );

            $scoped->assertOk();
            // Only this branch's own payment — the shared-account settlement is
            // ambiguous and therefore unattributed, exactly like a NULL-location
            // row, rather than replicated to all four branches.
            $scoped->assertJsonCount(1, 'data');
            $scoped->assertJsonPath('data.0.source_id', $branchPayments[$suffix]->id);
            $scoped->assertJsonPath('data.0.amount', $expected);
            $scoped->assertJsonPath('meta.totals.EUR.in', $expected);
            // Σ over the branch scopes (0.00 out) never exceeds the All figure
            // (25.00 out): no branch claims the company-level outflow.
            $scoped->assertJsonPath('meta.totals.EUR.out', '0.00');

            $seenSourceIds[] = (string) $scoped->json('data.0.source_id');
        }

        // Pairwise disjointness across the four branch scopes.
        $this->assertSame($seenSourceIds, array_values(array_unique($seenSourceIds)));
    }

    public function test_a_deactivated_register_stops_granting_its_branch_journal_visibility(): void
    {
        $shopA = $this->location('SHOP-A', 'Shop A');
        $shopB = $this->location('SHOP-B', 'Shop B');

        $this->cashRepository->update(['location_id' => $shopA->id]);
        $this->bankRepository->update(['location_id' => $shopB->id, 'is_active' => false]);

        $entry = $this->journalEntry('2026-07-24', 'manual_bank_sale', Str::uuid()->toString());
        $this->journalLine($entry, $this->bankAccount, '40.000', '0.000', 'Shop B bank sale');
        $this->journalLine($entry, $this->revenueAccount, '0.000', '40.000', 'Revenue');

        $scopedToB = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-24&to=2026-07-24&location_ids[]={$shopB->id}");

        $scopedToB->assertOk();
        $scopedToB->assertJsonCount(0, 'data');

        $scopedToA = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/reports/cash-movements?from=2026-07-24&to=2026-07-24&location_ids[]={$shopA->id}");

        $scopedToA->assertOk();
        $scopedToA->assertJsonCount(0, 'data');

        // The unscoped read applies no location predicate at all, so the line is
        // still visible there — it is unattributed, not deleted.
        $unscoped = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-24&to=2026-07-24');

        $unscoped->assertOk();
        $unscoped->assertJsonCount(1, 'data');
        $unscoped->assertJsonPath('data.0.amount', '40.00');
    }

    public function test_a_malformed_location_id_is_rejected_by_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-20&to=2026-07-20&location_ids[]=not-a-uuid');

        $response->assertStatus(422);
    }

    private function location(string $code, string $name): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'code' => $code,
            'type' => 'shop',
            'is_active' => true,
            'pos_enabled' => true,
        ]);
    }

    private function account(string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
        ]);
    }

    private function repository(
        string $code,
        string $name,
        RepositoryType $type,
        Account $glAccount,
    ): PaymentRepository {
        return PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'balance' => '0.000',
            'gl_account_id' => $glAccount->id,
            'is_active' => true,
        ]);
    }

    private function payment(
        PaymentRepository $repository,
        string $amount,
        string $paymentDate,
        PaymentType $paymentType,
        PaymentOrigin $origin = PaymentOrigin::WebAdmin,
        ?string $fiscalEventId = null,
        string $currency = 'EUR',
        ?string $locationId = null,
    ): Payment {
        return Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'location_id' => $locationId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_date' => $paymentDate,
            'status' => PaymentStatus::Completed,
            'payment_type' => $paymentType,
            'origin' => $origin,
            'fiscal_event_id' => $fiscalEventId,
            'reference' => 'PAY-'.Str::uuid()->toString(),
        ]);
    }

    private function journalEntry(
        string $date,
        string $sourceType,
        string $sourceId,
        JournalEntryStatus $status = JournalEntryStatus::Posted,
    ): JournalEntry {
        return JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.Str::uuid()->toString(),
            'entry_date' => $date,
            'description' => 'Cash movement test entry',
            'status' => $status,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'posted_at' => $status === JournalEntryStatus::Posted ? now() : null,
        ]);
    }

    private function journalLine(
        JournalEntry $entry,
        Account $account,
        string $debit,
        string $credit,
        string $description,
    ): JournalLine {
        return JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'line_order' => (int) JournalLine::where('journal_entry_id', $entry->id)->count(),
        ]);
    }

    private function fiscalEvent(Terminal $terminal, string $businessDate): string
    {
        $id = Str::uuid()->toString();

        DB::table('fiscal_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'signature_version' => 'none',
            'sequence_number' => DB::table('fiscal_events')->count() + 1,
            'event_time_device' => $businessDate.' 10:00:00',
            'business_date' => $businessDate,
            'server_received_at' => $businessDate.' 10:00:01',
            'canonical_bytes' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $id),
            'payload_parse_status' => 'parsed',
            'payload' => json_encode(['type' => 'SALE_RECEIPT'], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
