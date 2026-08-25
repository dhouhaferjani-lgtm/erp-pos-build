<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ExpensePostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Posting a paid expense with a payment_repository_id decrements the
     * repository balance by the expense total, and a GL journal entry is
     * created for the expense.
     */
    public function test_posting_paid_expense_decrements_repository_balance_and_creates_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, [
            'total' => '150.000',
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post");

        $response->assertOk();

        // Balance decremented: 500.000 - 150.000 = 350.000
        $this->assertSame('350.000', $repo->fresh()->balance);

        // GL journal entry created for the expense
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'expense',
            'source_id' => $expense->id,
            'company_id' => $company->id,
        ]);
    }

    /**
     * Re-posting an already-posted expense returns 422 and does NOT
     * double-decrement the repository balance.
     */
    public function test_reposting_already_posted_expense_returns_422_and_does_not_double_decrement(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, [
            'total' => '150.000',
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        // First post succeeds — balance goes to 350.000
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $this->assertSame('350.000', $repo->fresh()->balance);

        // Second post must return 422
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertStatus(422);

        // Balance MUST NOT have been decremented again
        $this->assertSame('350.000', $repo->fresh()->balance);
    }

    /**
     * Posting a paid expense WITHOUT a payment_repository_id (no repo) only
     * creates the GL entry — there is no balance to decrement.
     */
    public function test_posting_paid_expense_without_repository_still_creates_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $expense = $this->makeExpense($company, $user, [
            'total' => '80.000',
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => null,
            'payment_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post");

        $response->assertOk();

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'expense',
            'source_id' => $expense->id,
        ]);
    }

    /**
     * Q-11 (Treasury sub-report): `generateExpenseNumber` deduped within
     * `company_id`, but the only unique index on the column is
     * `documents_tenant_id_type_document_number_unique` on
     * `(tenant_id, type, document_number)`. In a tenant with two companies the
     * second company's first expense post generated `EXP-YYYY-000001`, which the
     * first company already held — an unconditional unique violation that rolls
     * back the whole post transaction (GL entry, VAT detail, WAC capitalisation).
     */
    public function test_expense_numbers_do_not_collide_across_two_companies_in_the_same_tenant(): void
    {
        [$user, $companyA] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
        $companyB = $this->makeSiblingCompany($user, 'Second Company');

        // Company A already holds this tenant's EXP-<year>-000001.
        $numberA = sprintf('EXP-%s-%06d', date('Y'), 1);
        $this->makeExpense($companyA, $user, [
            'status' => DocumentStatus::Posted,
            'document_number' => $numberA,
            'total' => '120.000',
            'currency' => 'TND',
        ]);

        // Company B posts ITS first expense. A company-scoped max+1 mints
        // EXP-<year>-000001 again and violates
        // documents_tenant_id_type_document_number_unique.
        $numberB = $this->postExpenseForCompany($user, $companyB, '140.000');

        $this->assertNotSame(
            $numberA,
            $numberB,
            'Expense numbers must be unique tenant-wide — the unique index is (tenant_id, type, document_number).'
        );
        $this->assertSame(sprintf('EXP-%s-%06d', date('Y'), 2), $numberB);
    }

    /**
     * Q-11: the max+1 read must be serialised by a transaction-scoped advisory
     * lock (the shape `InstrumentRemittance::allocateNumber()` already uses), keyed
     * on the SAME scope the scan uses — the tenant.
     */
    public function test_expense_number_allocation_takes_the_tenant_advisory_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('pg_advisory_xact_lock is observable on PostgreSQL only.');
        }

        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);

        DB::enableQueryLog();
        $this->postExpenseForCompany($user, $company, '90.000');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $locks = array_values(array_filter(
            $log,
            static fn (array $entry): bool => str_contains((string) $entry['query'], 'pg_advisory_xact_lock(hashtextextended')
                && in_array("expense_number:{$user->tenant_id}", array_map(strval(...), $entry['bindings']), true)
        ));

        $this->assertNotSame(
            [],
            $locks,
            'Expected a pg_advisory_xact_lock keyed expense_number:{tenantId} during expense-number allocation.'
        );
    }

    /**
     * Post one expense for the given company and return the allocated number.
     */
    private function postExpenseForCompany(User $user, Company $company, string $total): string
    {
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $expense = $this->makeExpense($company, $user, [
            'total' => $total,
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => null,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $number = $expense->fresh()?->document_number;
        $this->assertIsString($number);

        return $number;
    }

    /**
     * Create a second company under the same tenant, with the user a member of it.
     */
    private function makeSiblingCompany(User $user, string $name): Company
    {
        $company = Company::create([
            'tenant_id' => $user->tenant_id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX'.uniqid(),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        return $company;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeExpense(Company $company, User $user, array $overrides = []): Document
    {
        $partner = Partner::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'name' => 'Test Vendor',
            'type' => PartnerType::Supplier,
        ]);

        return Document::create(array_merge([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Expense,
            'status' => DocumentStatus::Draft,
            'document_number' => 'EXP-DRAFT-'.uniqid(),
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
