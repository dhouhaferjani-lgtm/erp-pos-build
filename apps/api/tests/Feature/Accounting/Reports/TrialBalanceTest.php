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

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $revenueAccount;

    private Account $expenseAccount;

    private Account $apAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'TB Test Tenant',
            'slug' => 'tb-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TB Test Company',
            'legal_name' => 'TB Test Company LLC',
            'tax_id' => 'TBTAX123',
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
            'name' => 'TB Test User',
            'email' => 'tb-user@example.com',
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

        $this->apAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '2100',
            'name' => 'Accounts Payable',
            'type' => AccountType::Liability,
        ]);
    }

    /**
     * Helper to create a posted journal entry with balanced lines.
     *
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

    public function test_trial_balance_with_posted_entries_shows_correct_balances(): void
    {
        // Create a sale: debit cash 1000, credit revenue 1000
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '1000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '1000.00'],
        ]);

        // Create an expense: debit expense 200, credit cash 200
        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->expenseAccount->id, 'debit' => '200.00', 'credit' => '0.00'],
            ['account_id' => $this->cashAccount->id, 'debit' => '0.00', 'credit' => '200.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertNotEmpty($data['lines']);
        $this->assertNotEmpty($data['total_debit']);
        $this->assertNotEmpty($data['total_credit']);

        // Find cash account line (debit 1000 - credit 200 = net 800 debit)
        $cashLine = collect($data['lines'])->firstWhere('account_code', '1100');
        $this->assertNotNull($cashLine, 'Cash account should appear in trial balance');
        // Cash has net debit balance of 800
        $this->assertTrue(
            bccomp($cashLine['debit'], '0', 2) > 0,
            'Cash should have a debit balance'
        );

        // Find revenue account line (credit 1000 = net -1000 = credit column 1000)
        $revenueLine = collect($data['lines'])->firstWhere('account_code', '4000');
        $this->assertNotNull($revenueLine, 'Revenue account should appear in trial balance');
        $this->assertTrue(
            bccomp($revenueLine['credit'], '0', 2) > 0,
            'Revenue should have a credit balance'
        );
    }

    public function test_trial_balance_is_balanced_debits_equal_credits(): void
    {
        // Create balanced entries
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '5000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '5000.00'],
        ]);

        $this->createPostedEntry('2025-06-20', [
            ['account_id' => $this->expenseAccount->id, 'debit' => '1500.00', 'credit' => '0.00'],
            ['account_id' => $this->apAccount->id, 'debit' => '0.00', 'credit' => '1500.00'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['is_balanced'], 'Trial balance must be balanced (debits = credits)');
        $this->assertEquals(
            0,
            bccomp($data['total_debit'], $data['total_credit'], 4),
            "Total debits ({$data['total_debit']}) must equal total credits ({$data['total_credit']})"
        );
    }

    public function test_trial_balance_zero_balance_accounts_excluded_by_default(): void
    {
        // Create an account with no transactions
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1200',
            'name' => 'Bank Account',
            'type' => AccountType::Asset,
        ]);

        // Only the cash account gets a transaction
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '500.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '500.00'],
        ]);

        // Without include_zero_balances, zero-balance accounts should be excluded
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_zero_balances=false&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        $bankLine = collect($data['lines'])->firstWhere('account_code', '1200');
        $this->assertNull($bankLine, 'Zero-balance accounts should be excluded by default');

        // With include_zero_balances=true, it should appear
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_zero_balances=true&include_hierarchy=false');

        $response2->assertOk();
        $data2 = $response2->json('data');

        $bankLine2 = collect($data2['lines'])->firstWhere('account_code', '1200');
        $this->assertNotNull($bankLine2, 'Zero-balance accounts should appear when include_zero_balances=true');
    }

    public function test_left_join_preserves_zero_balance_accounts_with_include_flag(): void
    {
        // Scenario 1: Account with NO journal entries at all
        $noEntriesAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1200',
            'name' => 'Bank Account (No Entries)',
            'type' => AccountType::Asset,
        ]);

        // Scenario 2: Account WITH journal entries that net to zero balance
        // (debit and credit cancel out)
        $zeroNetAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1300',
            'name' => 'Clearing Account (Zero Net)',
            'type' => AccountType::Asset,
        ]);

        // Create entries that net to zero on the clearing account:
        // Entry 1: debit clearing 500, credit revenue 500
        $this->createPostedEntry('2025-06-10', [
            ['account_id' => $zeroNetAccount->id, 'debit' => '500.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '500.00'],
        ]);
        // Entry 2: credit clearing 500, debit cash 500 (nets clearing to zero)
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '500.00', 'credit' => '0.00'],
            ['account_id' => $zeroNetAccount->id, 'debit' => '0.00', 'credit' => '500.00'],
        ]);

        // Scenario 3: Account with a non-zero balance (cash has net 500 debit)
        // (already created by entries above — cashAccount has debit 500, no credits to it directly)

        // --- Test with include_zero_balances=false ---
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_zero_balances=false&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');
        $lines = collect($data['lines']);

        // No-entries account should be excluded (zero balance, no flag)
        $this->assertNull(
            $lines->firstWhere('account_code', '1200'),
            'Account with no entries should be excluded when include_zero_balances=false'
        );

        // Zero-net account should be excluded (has entries but balance is zero)
        $this->assertNull(
            $lines->firstWhere('account_code', '1300'),
            'Account with zero net balance should be excluded when include_zero_balances=false'
        );

        // Non-zero balance account should always appear
        $cashLine = $lines->firstWhere('account_code', '1100');
        $this->assertNotNull($cashLine, 'Account with non-zero balance must always appear');
        $this->assertEquals(0, bccomp($cashLine['debit'], '500.0000', 4), 'Cash should have 500 debit balance');

        // --- Test with include_zero_balances=true ---
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_zero_balances=true&include_hierarchy=false');

        $response2->assertOk();
        $data2 = $response2->json('data');
        $lines2 = collect($data2['lines']);

        // No-entries account SHOULD appear (LEFT JOIN must preserve it)
        $noEntriesLine = $lines2->firstWhere('account_code', '1200');
        $this->assertNotNull(
            $noEntriesLine,
            'Account with no entries MUST appear when include_zero_balances=true — '
            .'if missing, the LEFT JOIN is being converted to INNER JOIN'
        );

        // Zero-net account SHOULD appear (has entries but zero net)
        $zeroNetLine = $lines2->firstWhere('account_code', '1300');
        $this->assertNotNull(
            $zeroNetLine,
            'Account with zero net balance MUST appear when include_zero_balances=true'
        );

        // Non-zero balance account should still appear
        $this->assertNotNull(
            $lines2->firstWhere('account_code', '1100'),
            'Account with non-zero balance must appear regardless of flag'
        );

        // Verify balance is still correct
        $this->assertTrue($data2['is_balanced'], 'Trial balance must remain balanced');
    }

    public function test_trial_balance_date_range_filtering(): void
    {
        // Entry in January
        $this->createPostedEntry('2025-01-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '1000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '1000.00'],
        ]);

        // Entry in July
        $this->createPostedEntry('2025-07-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '2000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '2000.00'],
        ]);

        // As of March 31 — should only see January entry
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-03-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Total debits should be 1000 (only Jan entry), not 3000
        $this->assertEquals(
            0,
            bccomp($data['total_debit'], '1000.0000', 4),
            "As-of March 31 should only include January entry. Got total_debit: {$data['total_debit']}"
        );

        // As of December 31 — should see both entries
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_hierarchy=false');

        $response2->assertOk();
        $data2 = $response2->json('data');

        $this->assertEquals(
            0,
            bccomp($data2['total_debit'], '3000.0000', 4),
            "As-of December 31 should include both entries. Got total_debit: {$data2['total_debit']}"
        );
    }

    public function test_trial_balance_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/trial-balance');

        $response->assertUnauthorized();
    }

    public function test_trial_balance_requires_reports_view_permission(): void
    {
        $userWithoutPermission = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission User',
            'email' => 'noperm-tb@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($userWithoutPermission)
            ->getJson('/api/v1/reports/trial-balance');

        $response->assertForbidden();
    }

    public function test_trial_balance_draft_entries_excluded(): void
    {
        // Create a posted entry
        $this->createPostedEntry('2025-06-15', [
            ['account_id' => $this->cashAccount->id, 'debit' => '1000.00', 'credit' => '0.00'],
            ['account_id' => $this->revenueAccount->id, 'debit' => '0.00', 'credit' => '1000.00'],
        ]);

        // Create a draft entry — should NOT affect trial balance
        $draftEntry = JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => 'JE-DRAFT-001',
            'entry_date' => '2025-06-20',
            'description' => 'Draft entry',
            'status' => JournalEntryStatus::Draft,
        ]);

        JournalLine::create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $this->cashAccount->id,
            'debit' => '9999.00',
            'credit' => '0.00',
            'line_order' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $draftEntry->id,
            'account_id' => $this->revenueAccount->id,
            'debit' => '0.00',
            'credit' => '9999.00',
            'line_order' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/trial-balance?as_of_date=2025-12-31&include_hierarchy=false');

        $response->assertOk();
        $data = $response->json('data');

        // Total debits should be 1000 (only posted), not 10999
        $this->assertEquals(
            0,
            bccomp($data['total_debit'], '1000.0000', 4),
            "Draft entries should be excluded. Got total_debit: {$data['total_debit']}"
        );
    }
}
