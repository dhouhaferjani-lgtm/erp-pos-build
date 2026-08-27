<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

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
use App\Modules\Expense\Domain\ExpenseMetadata;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LEDGER C-27 (Session B2 lane B2-1).
 *
 * `journal_entries` carries exactly ONE unique index on the number column —
 * `journal_entries_tenant_id_entry_number_unique` on `(tenant_id, entry_number)`
 * (`database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php`).
 * `GeneralLedgerService::generateEntryNumber()` allocated with a COMPANY-scoped
 * max+1 scan, i.e. a scope NARROWER than the constraint it must satisfy. In a
 * tenant with two companies, the second company's first journal entry of the year
 * minted `JE-YYYY-000001` — a number the first company already held — so EVERY
 * JE-minting flow for that company 500'd on SQLSTATE 23505 and the whole posting
 * transaction rolled back. The company was GL-dead for the rest of the year.
 */
final class JournalEntryNumberingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * T1 — the reproduction. Two companies of ONE tenant each post an expense
     * through the real `GeneralLedgerService::createFromExpense` path. Before the
     * fix, company B's post died on the tenant-wide unique index. After it, B
     * receives `JE-YYYY-000002` and both rows persist.
     */
    public function test_journal_entry_numbers_do_not_collide_across_two_companies_in_the_same_tenant(): void
    {
        [$user, $companyA] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
        $companyB = $this->makeSiblingCompany($user, 'Second Company');

        $expenseA = $this->postExpenseForCompany($user, $companyA, '120.000');
        $expenseB = $this->postExpenseForCompany($user, $companyB, '140.000');

        $entryA = $this->journalEntryForExpense($expenseA);
        $entryB = $this->journalEntryForExpense($expenseB);

        $year = date('Y');
        self::assertSame(sprintf('JE-%s-%06d', $year, 1), $entryA->entry_number);
        self::assertSame(
            sprintf('JE-%s-%06d', $year, 2),
            $entryB->entry_number,
            'Entry numbers must be unique tenant-wide — the unique index is (tenant_id, entry_number).'
        );

        // Both rows really persisted (company B's transaction did not roll back).
        self::assertSame(2, JournalEntry::query()->where('tenant_id', $user->tenant_id)->count());
        self::assertSame($companyA->id, $entryA->company_id);
        self::assertSame($companyB->id, $entryB->company_id);
    }

    /**
     * T1b — the hash chain stays PER COMPANY. Widening the NUMBER scope to the
     * tenant must not widen the chain scope: each company's first posted entry is
     * chain_sequence 1.
     */
    public function test_chain_sequence_stays_per_company_after_the_numbering_widens_to_the_tenant(): void
    {
        [$user, $companyA] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
        $companyB = $this->makeSiblingCompany($user, 'Second Company');

        $expenseA = $this->postExpenseForCompany($user, $companyA, '120.000');
        $expenseB = $this->postExpenseForCompany($user, $companyB, '140.000');

        $entryA = $this->journalEntryForExpense($expenseA);
        $entryB = $this->journalEntryForExpense($expenseB);

        self::assertSame(1, $entryA->chain_sequence, 'Company A holds the first link of ITS chain.');
        self::assertSame(1, $entryB->chain_sequence, 'Company B holds the first link of ITS OWN chain.');
    }

    /**
     * T2 — the allocation is serialised by a transaction-scoped advisory lock
     * keyed on the SAME scope the scan uses (the tenant), and — because the
     * per-company chain lock in `sealAndPersistEntry` is still taken — the global
     * order is tenant key FIRST, company key second. Observable on PostgreSQL only.
     */
    public function test_entry_number_allocation_takes_the_tenant_lock_before_the_company_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('pg_advisory_xact_lock is observable on PostgreSQL only.');
        }

        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);

        DB::enableQueryLog();
        $this->postExpenseForCompany($user, $company, '90.000');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $tenantLockAt = $this->firstLockIndex($log, "journal_entry_number:{$user->tenant_id}");
        $companyLockAt = $this->firstLockIndex($log, $company->id);

        self::assertNotNull(
            $tenantLockAt,
            'Expected a pg_advisory_xact_lock keyed journal_entry_number:{tenantId} during entry-number allocation.'
        );
        self::assertNotNull(
            $companyLockAt,
            'The per-company chain lock must still be taken — the hash chain is per company.'
        );
        self::assertLessThan(
            $companyLockAt,
            $tenantLockAt,
            'Global lock order is load-bearing: the tenant numbering key is always taken BEFORE the company chain key.'
        );
    }

    /**
     * T3 — the posting transaction actually HOLDS the tenant numbering key.
     *
     * Gate r1 (treasury lens) F-6: the previous form of this test asserted only
     * that PostgreSQL's `pg_try_advisory_xact_lock` is mutually exclusive across
     * two connections. It never invoked the service, so it was green on the
     * unfixed base — a test that cannot fail. It now drives a REAL post and, while
     * that transaction is still open, proves from a SECOND connection that
     * `journal_entry_number:{tenantId}` is unavailable: the service is holding it.
     * On the unfixed base no such lock is taken and the probe succeeds.
     */
    public function test_the_posting_transaction_holds_the_tenant_numbering_key(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('pg_advisory_xact_lock is observable on PostgreSQL only.');
        }

        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
        $key = "journal_entry_number:{$user->tenant_id}";

        $config = DB::connection()->getConfig();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $other = new \PDO($dsn, (string) $config['username'], (string) $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Sanity: nobody holds the key before the post. (Taken and released in the
        // probe connection's own single-statement transaction.)
        $free = $other->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))');
        $free->execute([$key]);
        self::assertTrue((bool) $free->fetchColumn(), 'The key must be free before the posting transaction opens.');

        DB::transaction(function () use ($user, $company, $other, $key): void {
            $this->postExpenseForCompany($user, $company, '90.000');

            // Still inside the posting transaction: the key must be UNAVAILABLE to
            // anyone else. This is the property the fix creates — the max+1 read is
            // serialised until commit.
            $probe = $other->prepare('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))');
            $probe->execute([$key]);
            self::assertFalse(
                (bool) $probe->fetchColumn(),
                'journal_entry_number:{tenantId} must be HELD for the life of the posting transaction — '
                .'otherwise two concurrent minters read the same maximum.'
            );
        });
    }

    /**
     * T5 — the MANUAL journal-entry endpoint mints from the same sequence, so it
     * must take the IDENTICAL lock key or it races the service minter.
     */
    public function test_manual_journal_entry_creation_takes_the_same_tenant_lock_key(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('pg_advisory_xact_lock is observable on PostgreSQL only.');
        }

        [$user, $company] = $this->makeUserWithPermissions(['journal.view', 'journal.create', 'journal.post']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $cash = $this->makeAccount($user, $company, '1100', 'Cash', AccountType::Asset);
        $revenue = $this->makeAccount($user, $company, '7000', 'Revenue', AccountType::Revenue);

        DB::enableQueryLog();
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => now()->toDateString(),
                'description' => 'Manual entry',
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => '100.000', 'credit' => '0.000'],
                    ['account_id' => $revenue->id, 'debit' => '0.000', 'credit' => '100.000'],
                ],
            ])
            ->assertStatus(201);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertNotNull(
            $this->firstLockIndex($log, "journal_entry_number:{$user->tenant_id}"),
            'The manual JE endpoint shares one sequence with the service minter and must share its lock key.'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Index of the first `pg_advisory_xact_lock` statement whose bindings contain
     * the given value, or null when no such statement was logged.
     *
     * @param  array<int, array{query: string, bindings: array<int|string, mixed>, time: float|null}>  $log
     */
    private function firstLockIndex(array $log, string $binding): ?int
    {
        foreach (array_values($log) as $index => $entry) {
            if (! str_contains((string) $entry['query'], 'pg_advisory_xact_lock(hashtextextended')) {
                continue;
            }

            $bindings = array_map(static fn (mixed $value): string => (string) $value, $entry['bindings']);
            if (in_array($binding, $bindings, true)) {
                return $index;
            }
        }

        return null;
    }

    private function journalEntryForExpense(Document $expense): JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->firstOrFail();
    }

    private function makeAccount(User $user, Company $company, string $code, string $name, AccountType $type): Account
    {
        return Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    /**
     * Post one expense for the given company and return the (fresh) document.
     */
    private function postExpenseForCompany(User $user, Company $company, string $total): Document
    {
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $expense = $this->makeExpense($company, $user, [
            'total' => $total,
            'currency' => 'TND',
        ]);

        ExpenseMetadata::create([
            'document_id' => $expense->id,
            // This test exercises journal-entry numbering, not the paid-expense
            // treasury path. A paid expense must name a repository; keep this
            // fixture unpaid so posting reaches the GL path under test.
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $fresh = $expense->fresh();
        self::assertNotNull($fresh);

        return $fresh;
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
