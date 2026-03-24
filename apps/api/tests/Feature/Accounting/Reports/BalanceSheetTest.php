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

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $equipmentAccount;

    private Account $apAccount;

    private Account $capitalAccount;

    private Account $revenueAccount;

    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'BS Test Tenant',
            'slug' => 'bs-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'BS Test Company',
            'legal_name' => 'BS Test Company LLC',
            'tax_id' => 'BSTAX123',
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
            'name' => 'BS Test User',
            'email' => 'bs-user@example.com',
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

        // Assets
        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
        ]);

        $this->equipmentAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1500',
            'name' => 'Equipment',
            'type' => AccountType::Asset,
        ]);

        // Liabilities
        $this->apAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '2100',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability,
        ]);

        // Equity
        $this->capitalAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3000',
            'name' => 'Capital Stock',
            'type' => AccountType::Equity,
        ]);

        // Revenue & Expense (for retained earnings calculation)
        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
        ]);

        $this->expenseAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6000',
            'name' => 'Rent Expense',
            'type' => AccountType::Expense,
        ]);
    }

    /**
     * @param list<array{account_id: string, debit: string, credit: string}> $lines
     */
    private function createPostedEntry(string $date, array $lines, string $description = 'Test entry'): JournalEntry
    {
        $entry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-' . uniqid(),
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

    public function test_balance_sheet_assets_equal_liabilities_plus_equity(): void
    {
        // Owner invests capital: debit cash 50000, credit capital 50000
        $this->createPostedEntry('2025-01-01', [
            ['account_id' => $this->cashAccount->id, 'debit' => '50000.00', 'credit' => '0.00'],
            ['account_id' => $this->capitalAccount->id, 'debit' => '0.00', 'credit' => '50000.00'],
        ]);

        // Purchase equipment on credit: debit equipment 10000, credit AP 10000
        $this->createPostedEntry('2025-02-01', [
            ['account_id' => $this->equipmentAccount->id, 'debit' => '10000.00', 'credit' => '0.00'],
            ['account_id' => $this->apAccount->id, 'debit' => '0.00', 'credit' => '10000.00'],
        ]);

        // Revenue: debit cash 8000, credit revenue 8000
        $this->createPostedEntry('2025-03-01', [
            ['account_id' => $this->cashAccount->id, 'debit' => '8000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '8000.00'],
        ]);

        // Expense: debit rent 3000, credit cash 3000
        $this->createPostedEntry('2025-03-15', [
            ['account_id' => $this->expenseAccount->id, 'debit' => '3000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '3000.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/balance-sheet?as_of_date=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Assets = Cash (50000 + 8000 - 3000 = 55000) + Equipment (10000) = 65000
        $expectedAssets = '65000.0000';
        $this->assertEquals(
            0,
            bccomp($data['total_assets'], $expectedAssets, 4),
            "Expected total_assets {$expectedAssets}, got {$data['total_assets']}"
        );

        // Liabilities = AP (10000)
        $expectedLiabilities = '10000.0000';
        $this->assertEquals(
            0,
            bccomp($data['total_liabilities'], $expectedLiabilities, 4),
            "Expected total_liabilities {$expectedLiabilities}, got {$data['total_liabilities']}"
        );

        // Equity = Capital (50000) + Retained Earnings (8000 - 3000 = 5000) = 55000
        $expectedEquity = '55000.0000';
        $this->assertEquals(
            0,
            bccomp($data['total_equity'], $expectedEquity, 4),
            "Expected total_equity {$expectedEquity}, got {$data['total_equity']}"
        );

        // Fundamental equation: Assets = Liabilities + Equity
        $this->assertTrue($data['is_balanced'], 'Balance sheet must be balanced: Assets = Liabilities + Equity');

        $liabPlusEquity = bcadd($data['total_liabilities'], $data['total_equity'], 4);
        $this->assertEquals(
            0,
            bccomp($data['total_assets'], $liabPlusEquity, 4),
            "Assets ({$data['total_assets']}) must equal Liabilities + Equity ({$liabPlusEquity})"
        );
    }

    public function test_retained_earnings_calculated_correctly(): void
    {
        // Capital investment
        $this->createPostedEntry('2025-01-01', [
            ['account_id' => $this->cashAccount->id, 'debit' => '20000.00', 'credit' => '0.00'],
            ['account_id' => $this->capitalAccount->id, 'debit' => '0.00', 'credit' => '20000.00'],
        ]);

        // Revenue: 15000
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '15000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '15000.00'],
        ]);

        // Expenses: 6000
        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->expenseAccount->id, 'debit' => '6000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '6000.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/balance-sheet?as_of_date=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Retained earnings = Revenue - Expenses = 15000 - 6000 = 9000
        $expectedRetainedEarnings = '9000.0000';
        $this->assertEquals(
            0,
            bccomp($data['retained_earnings'], $expectedRetainedEarnings, 4),
            "Expected retained_earnings {$expectedRetainedEarnings}, got {$data['retained_earnings']}"
        );
    }

    public function test_balance_sheet_as_of_date_filtering(): void
    {
        // January capital investment
        $this->createPostedEntry('2025-01-01', [
            ['account_id' => $this->cashAccount->id, 'debit' => '10000.00', 'credit' => '0.00'],
            ['account_id' => $this->capitalAccount->id, 'debit' => '0.00', 'credit' => '10000.00'],
        ]);

        // July equipment purchase
        $this->createPostedEntry('2025-07-01', [
            ['account_id' => $this->equipmentAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        // As of March 31 — only January entry should be included
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/balance-sheet?as_of_date=2025-03-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Assets should be 10000 (only cash from Jan investment)
        $this->assertEquals(
            0,
            bccomp($data['total_assets'], '10000.0000', 4),
            "As of March 31, total_assets should be 10000 (only Jan entry). Got: {$data['total_assets']}"
        );

        // As of December 31 — both entries included
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/balance-sheet?as_of_date=2025-12-31&include_hierarchy=false');

        $response2->assertOk();
        $data2 = $response2->json('data');

        // Assets = Cash (10000 - 5000 = 5000) + Equipment (5000) = 10000
        $this->assertEquals(
            0,
            bccomp($data2['total_assets'], '10000.0000', 4),
            "As of Dec 31, total_assets should be 10000. Got: {$data2['total_assets']}"
        );
    }

    public function test_balance_sheet_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/balance-sheet');

        $response->assertUnauthorized();
    }

    public function test_balance_sheet_requires_reports_view_permission(): void
    {
        $userWithoutPermission = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission User',
            'email' => 'noperm-bs@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($userWithoutPermission)
            ->getJson('/api/v1/reports/balance-sheet');

        $response->assertForbidden();
    }
}
