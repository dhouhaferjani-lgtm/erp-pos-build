<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test for GET /api/v1/reports/finance-summary (TD-012).
 *
 * The endpoint aggregates the numbers the Trésorerie FinanceWidget consumes
 * from the canonical report services (balance sheet, P&L, aged receivables /
 * payables). Before this endpoint existed the FE received a 404 and the widget
 * rendered zeros.
 */
final class FinanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $equipmentAccount;

    private Account $apLiabilityAccount;

    private Account $capitalAccount;

    private Account $revenueAccount;

    private Account $expenseAccount;

    private Partner $customer;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "today" so month-to-date / year-to-date windows are deterministic.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-06-15'));

        $this->tenant = Tenant::create([
            'name' => 'FS Test Tenant',
            'slug' => 'fs-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FS Test Company',
            'legal_name' => 'FS Test Company LLC',
            'tax_id' => 'FSTAX123',
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
            'name' => 'FS Test User',
            'email' => 'fs-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['reports.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashAccount = $this->makeAccount('1100', 'Cash', AccountType::Asset);
        $this->equipmentAccount = $this->makeAccount('1500', 'Equipment', AccountType::Asset);
        $this->apLiabilityAccount = $this->makeAccount('2100', 'Accounts Payable', AccountType::Liability);
        $this->capitalAccount = $this->makeAccount('3000', 'Capital Stock', AccountType::Equity);
        $this->revenueAccount = $this->makeAccount('4000', 'Sales Revenue', AccountType::Revenue);
        $this->expenseAccount = $this->makeAccount('6000', 'Rent Expense', AccountType::Expense);

        $this->customer = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
            'name' => 'Clinique Ennasr',
        ]);
        $this->supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Medis Distribution',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_finance_summary_returns_correct_shape_and_aggregated_numbers(): void
    {
        // --- Balance sheet + retained earnings backbone -----------------------
        // Owner invests capital: cash 50000 / capital 50000
        $this->createPostedEntry('2025-01-05', [
            ['account_id' => $this->cashAccount->id, 'debit' => '50000.00', 'credit' => '0.00'],
            ['account_id' => $this->capitalAccount->id, 'debit' => '0.00', 'credit' => '50000.00'],
        ]);
        // Buy equipment on credit: equipment 10000 / AP 10000
        $this->createPostedEntry('2025-03-01', [
            ['account_id' => $this->equipmentAccount->id, 'debit' => '10000.00', 'credit' => '0.00'],
            ['account_id' => $this->apLiabilityAccount->id, 'debit' => '0.00', 'credit' => '10000.00'],
        ]);

        // --- P&L: month-to-date (June) ---------------------------------------
        // Revenue this month: cash 8000 / revenue 8000
        $this->createPostedEntry('2025-06-10', [
            ['account_id' => $this->cashAccount->id, 'debit' => '8000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '8000.00'],
        ]);
        // Expense this month: expense 3000 / cash 3000
        $this->createPostedEntry('2025-06-12', [
            ['account_id' => $this->expenseAccount->id, 'debit' => '3000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '3000.00'],
        ]);
        // Earlier-this-year revenue (YTD but not MTD): cash 5000 / revenue 5000
        $this->createPostedEntry('2025-02-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        // --- Outstanding receivables / payables -------------------------------
        $this->createOpenDocument(DocumentType::Invoice, $this->customer, '300.00', '2025-05-01');
        $this->createOpenDocument(DocumentType::PurchaseOrder, $this->supplier, '200.00', '2025-05-01');

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/finance-summary');

        $response->assertOk();

        // Shape: every widget field present.
        $response->assertJsonStructure([
            'data' => [
                'total_assets',
                'total_liabilities',
                'total_equity',
                'net_income_mtd',
                'net_income_ytd',
                'accounts_receivable',
                'accounts_payable',
            ],
        ]);

        // Numbers (EUR → 2-decimal money strings).
        // Assets = cash(50000+8000-3000+5000=60000) + equipment(10000) = 70000
        $response->assertJsonPath('data.total_assets', '70000.00');
        // Liabilities = AP account (10000)
        $response->assertJsonPath('data.total_liabilities', '10000.00');
        // Equity = capital(50000) + retained earnings(rev 13000 - exp 3000 = 10000) = 60000
        $response->assertJsonPath('data.total_equity', '60000.00');
        // Net income MTD (June) = revenue 8000 - expense 3000 = 5000
        $response->assertJsonPath('data.net_income_mtd', '5000.00');
        // Net income YTD = revenue(8000+5000) - expense(3000) = 10000
        $response->assertJsonPath('data.net_income_ytd', '10000.00');
        // AR = outstanding customer invoices grand total
        $response->assertJsonPath('data.accounts_receivable', '300.00');
        // AP = outstanding purchase orders grand total
        $response->assertJsonPath('data.accounts_payable', '200.00');
    }

    public function test_finance_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/finance-summary')->assertUnauthorized();
    }

    public function test_finance_summary_requires_reports_view_permission(): void
    {
        $userWithoutPermission = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission User',
            'email' => 'noperm-fs@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/reports/finance-summary')
            ->assertForbidden();
    }

    private function makeAccount(string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * @param  list<array{account_id: string, debit: string, credit: string}>  $lines
     */
    private function createPostedEntry(string $date, array $lines): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'description' => 'Test entry',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
        ]);

        $lineOrder = 1;
        foreach ($lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'line_order' => $lineOrder++,
            ]);
        }

        return $entry;
    }

    private function createOpenDocument(
        DocumentType $type,
        Partner $partner,
        string $balanceDue,
        string $documentDate,
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'status' => DocumentStatus::Posted,
            'document_number' => $type->value.'-'.uniqid(),
            'document_date' => $documentDate,
            'due_date' => $documentDate,
            'currency' => 'EUR',
            'subtotal' => $balanceDue,
            'tax_amount' => '0.00',
            'total' => $balanceDue,
            'balance_due' => $balanceDue,
        ]);
    }
}
