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
        $this->user->givePermissionTo(['reports.view']);

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
        $payment = $this->payment(
            repository: $this->cashRepository,
            amount: '42.000',
            paymentDate: '2026-07-02',
            paymentType: PaymentType::DocumentPayment,
        );

        $entry = $this->journalEntry('2026-07-02', 'payment', $payment->id);
        $this->journalLine($entry, $this->cashAccount, '42.000', '0.000', 'Duplicated payment cash line');
        $this->journalLine($entry, $this->revenueAccount, '0.000', '42.000', 'Offset');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-07-02&to=2026-07-02');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source_type', 'payment');
        $response->assertJsonPath('data.0.source_id', $payment->id);
        $response->assertJsonPath('data.0.amount', '42.00');
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

    public function test_cash_movements_report_validates_date_filters(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/cash-movements?from=2026-99-99&to=2026-07-01');

        $response->assertUnprocessable();
        $response->assertJsonPath('error.errors.from.0', 'The from field must match the format Y-m-d.');
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
    ): Payment {
        return Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $repository->id,
            'amount' => $amount,
            'currency' => 'EUR',
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
