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
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $salesRevenue;

    private Account $serviceRevenue;

    private Account $rentExpense;

    private Account $salaryExpense;

    private Account $assetAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'PL Test Tenant',
            'slug' => 'pl-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PL Test Company',
            'legal_name' => 'PL Test Company LLC',
            'tax_id' => 'PLTAX123',
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
            'name' => 'PL Test User',
            'email' => 'pl-user@example.com',
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

        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->salesRevenue = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
        ]);

        $this->serviceRevenue = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4100',
            'name' => 'Service Revenue',
            'type' => AccountType::Revenue,
        ]);

        $this->rentExpense = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6000',
            'name' => 'Rent Expense',
            'type' => AccountType::Expense,
        ]);

        $this->salaryExpense = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6100',
            'name' => 'Salary Expense',
            'type' => AccountType::Expense,
        ]);

        $this->assetAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1500',
            'name' => 'Equipment',
            'type' => AccountType::Asset,
        ]);
    }

    /**
     * @param  list<array{account_id: string, debit: string, credit: string}>  $lines
     */
    private function createPostedEntry(string $date, array $lines, string $description = 'Test entry'): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'description' => $description,
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

    public function test_revenue_and_expense_accounts_appear_correctly(): void
    {
        // Sale: debit cash, credit revenue
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->salesRevenue->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        // Service income: debit cash, credit service revenue
        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->cashAccount->id, 'debit' => '2000.00', 'credit' => '0.00'],
            ['account_id' => $this->serviceRevenue->id, 'debit' => '0.00', 'credit' => '2000.00'],
        ]);

        // Expense: debit rent, credit cash
        $this->createPostedEntry('2025-06-25', [
            ['account_id' => $this->rentExpense->id, 'debit' => '1200.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '1200.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Revenue section should contain both revenue accounts
        $revenueCodes = collect($data['revenue'])->pluck('account_code')->toArray();
        $this->assertContains('4000', $revenueCodes, 'Sales Revenue should appear in revenue section');
        $this->assertContains('4100', $revenueCodes, 'Service Revenue should appear in revenue section');

        // Expense section should contain rent expense
        $expenseCodes = collect($data['expenses'])->pluck('account_code')->toArray();
        $this->assertContains('6000', $expenseCodes, 'Rent Expense should appear in expense section');

        // Revenue amounts should be positive
        $salesLine = collect($data['revenue'])->firstWhere('account_code', '4000');
        $this->assertTrue(
            bccomp($salesLine['amount'], '0', 4) > 0,
            'Sales revenue amount should be positive'
        );
    }

    public function test_net_income_calculated_correctly(): void
    {
        // Revenue: 5000 + 2000 = 7000
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->salesRevenue->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->cashAccount->id, 'debit' => '2000.00', 'credit' => '0.00'],
            ['account_id' => $this->serviceRevenue->id, 'debit' => '0.00', 'credit' => '2000.00'],
        ]);

        // Expenses: 1200 + 3000 = 4200
        $this->createPostedEntry('2025-06-25', [
            ['account_id' => $this->rentExpense->id, 'debit' => '1200.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '1200.00'],
        ]);

        $this->createPostedEntry('2025-06-28', [
            ['account_id' => $this->salaryExpense->id, 'debit' => '3000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '3000.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Total revenue = 7000
        $this->assertEquals(
            0,
            bccomp($data['total_revenue'], '7000.0000', 4),
            "Expected total_revenue 7000, got {$data['total_revenue']}"
        );

        // Total expenses = 4200
        $this->assertEquals(
            0,
            bccomp($data['total_expenses'], '4200.0000', 4),
            "Expected total_expenses 4200, got {$data['total_expenses']}"
        );

        // Net income = 7000 - 4200 = 2800
        $this->assertEquals(
            0,
            bccomp($data['net_income'], '2800.0000', 4),
            "Expected net_income 2800, got {$data['net_income']}"
        );
    }

    public function test_profit_loss_date_range_filtering(): void
    {
        // January revenue
        $this->createPostedEntry('2025-01-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '1000.00', 'credit' => '0.00'],
            ['account_id' => $this->salesRevenue->id, 'debit' => '0.00', 'credit' => '1000.00'],
        ]);

        // July revenue
        $this->createPostedEntry('2025-07-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '3000.00', 'credit' => '0.00'],
            ['account_id' => $this->salesRevenue->id, 'debit' => '0.00', 'credit' => '3000.00'],
        ]);

        // Q1 only (Jan-Mar): should only see Jan revenue
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-03-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(
            0,
            bccomp($data['total_revenue'], '1000.0000', 4),
            "Q1 revenue should be 1000. Got: {$data['total_revenue']}"
        );

        // Full year: should see both
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_hierarchy=false');

        $response2->assertOk();
        $data2 = $response2->json('data');

        $this->assertEquals(
            0,
            bccomp($data2['total_revenue'], '4000.0000', 4),
            "Full year revenue should be 4000. Got: {$data2['total_revenue']}"
        );
    }

    public function test_profit_loss_only_includes_revenue_and_expense_types(): void
    {
        // Revenue entry
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->salesRevenue->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        // Asset purchase — should not appear in P&L
        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->assetAccount->id, 'debit' => '10000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '10000.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Revenue should only have revenue-type accounts
        foreach ($data['revenue'] as $line) {
            $this->assertEquals('revenue', $line['account_type'], 'Revenue section must only contain revenue accounts');
        }

        // Expenses should only have expense-type accounts
        foreach ($data['expenses'] as $line) {
            $this->assertEquals('expense', $line['account_type'], 'Expense section must only contain expense accounts');
        }

        // Asset accounts (cash, equipment) should NOT appear anywhere in P&L
        $allCodes = collect($data['revenue'])->merge($data['expenses'])->pluck('account_code')->toArray();
        $this->assertNotContains('1100', $allCodes, 'Cash (asset) should not appear in P&L');
        $this->assertNotContains('1500', $allCodes, 'Equipment (asset) should not appear in P&L');
    }

    public function test_profit_loss_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31');

        $response->assertUnauthorized();
    }

    public function test_profit_loss_requires_reports_view_permission(): void
    {
        $userWithoutPermission = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission User',
            'email' => 'noperm-pl@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($userWithoutPermission)
            ->getJson('/api/v1/reports/profit-loss?date_from=2025-01-01&date_to=2025-12-31');

        $response->assertForbidden();
    }
}
