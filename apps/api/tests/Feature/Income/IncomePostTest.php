<?php

declare(strict_types=1);

namespace Tests\Feature\Income;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class IncomePostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Posting a received income with a payment_repository_id INCREASES the
     * repository balance by the income total, and a GL journal entry is created
     * that debits the repository's gl_account_id (NOT account_id) and credits
     * the class-7 income account.
     */
    public function test_posting_received_income_increments_repository_balance_and_creates_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $cashAccount = Account::query()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Asset)
            ->firstOrFail();
        $incomeAccount = Account::query()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Revenue)
            ->firstOrFail();

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $income = $this->makeIncome($company, $user, ['total' => '150.000', 'currency' => 'TND']);

        IncomeMetadata::create([
            'document_id' => $income->id,
            'income_account_id' => $incomeAccount->id,
            'is_received' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/income/{$income->id}/post");

        $response->assertOk();

        // Balance INCREMENTED: 500.000 + 150.000 = 650.000
        $this->assertSame('650.000', $repo->refresh()->balance);

        $entry = JournalEntry::query()
            ->where('source_type', 'income')
            ->where('source_id', $income->id)
            ->where('company_id', $company->id)
            ->with('lines')
            ->firstOrFail();

        // Debit leg must hit the repository's gl_account_id (the trap column),
        // NOT its account_id.
        $debitLine = $entry->lines->firstWhere('account_id', $cashAccount->id);
        $this->assertNotNull($debitLine);
        $this->assertSame(0, bccomp((string) $debitLine->debit, '150.000', 3));
        $this->assertSame(0, bccomp((string) $debitLine->credit, '0', 3));

        // Credit leg must hit the class-7 income account.
        $creditLine = $entry->lines->firstWhere('account_id', $incomeAccount->id);
        $this->assertNotNull($creditLine);
        $this->assertSame(0, bccomp((string) $creditLine->credit, '150.000', 3));
        $this->assertSame(0, bccomp((string) $creditLine->debit, '0', 3));
    }

    /**
     * Re-posting an already-posted income returns 422 and does NOT
     * double-increment the repository balance.
     */
    public function test_reposting_already_posted_income_returns_422_and_does_not_double_increment(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'type' => RepositoryType::CashRegister,
        ]);

        $income = $this->makeIncome($company, $user, ['total' => '150.000', 'currency' => 'TND']);

        IncomeMetadata::create([
            'document_id' => $income->id,
            'is_received' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/income/{$income->id}/post")
            ->assertOk();

        $this->assertSame('650.000', $repo->refresh()->balance);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/income/{$income->id}/post")
            ->assertStatus(422);

        $this->assertSame('650.000', $repo->refresh()->balance);
    }

    /**
     * Posting a received income WITHOUT a payment_repository_id only creates the
     * GL entry — there is no balance to increment.
     */
    public function test_posting_received_income_without_repository_still_creates_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $income = $this->makeIncome($company, $user, ['total' => '80.000', 'currency' => 'TND']);

        IncomeMetadata::create([
            'document_id' => $income->id,
            'is_received' => true,
            'payment_repository_id' => null,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/income/{$income->id}/post")
            ->assertOk();

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'income',
            'source_id' => $income->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeIncome(Company $company, User $user, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'type' => DocumentType::Income,
            'status' => DocumentStatus::Draft,
            'document_number' => null,
            'document_date' => now()->toDateString(),
            'currency' => 'TND',
            'subtotal' => '100.000',
            'tax_amount' => '0.000',
            'total' => '100.000',
            'balance_due' => '0.000',
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
