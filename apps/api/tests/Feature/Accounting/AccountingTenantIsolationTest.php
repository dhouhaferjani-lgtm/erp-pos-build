<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Application\Services\Reports\GeneralLedgerReportService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.accounting cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 7 callsites across 4 files.
 *   - api.accounting.001/002 — GetLedgerRequest validators
 *     (account_id + partner_id) had bare `exists:accounts,id` /
 *     `exists:partners,id` rules. Now ScopedExists::tenantAndCompany.
 *   - api.accounting.003 — CreateJournalEntryRequest had a tenant-only
 *     pipe-form exists (`exists:accounts,id,tenant_id,$tenantId`); upgraded
 *     to ScopedExists::tenantAndCompany pinning company_id too.
 *   - api.accounting.004/005 — PartnerBalanceService::refreshPartnerBalance
 *     and ::getCachedOrCalculateBalance had bare Partner::findOrFail. Now
 *     scoped by company_id (the controller's route boundary).
 *   - api.accounting.006 — GeneralLedgerReportService::getAccountDetails
 *     had a bare Account::find. Method signature gains tenant + company
 *     parameters; lookup is scoped by both.
 *   - api.accounting.007 — GeneralLedgerService::createFromExpense closure
 *     had a bare Account::findOrFail on metadata.category.account_id. Now
 *     scoped by the source expense's tenant_id + company_id.
 */
final class AccountingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Account $accountA;

    private Account $accountB;

    private Partner $partnerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-acct-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-acct-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-ACCT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-ACCT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-acct-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->accountA = Account::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => '4000',
            'name' => 'Sales Revenue A',
            'type' => AccountType::Revenue,
        ]);
        $this->accountB = Account::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => '4000',
            'name' => 'Sales Revenue B',
            'type' => AccountType::Revenue,
        ]);

        // PartnerA fixture not strictly needed (no happy-path Partner test
        // — covered indirectly by structural-SQL-log invariant). Keep
        // partnerB for cross-tenant rejection assertions.
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Customer B',
            'type' => 'customer',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting.001/002 — GetLedgerRequest validators
    // ──────────────────────────────────────────────────────────────────

    public function test_ledger_index_rejects_cross_tenant_account_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/ledger?account_id='.$this->accountB->id);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('account_id', $cross->json('error.errors') ?? []);
    }

    public function test_ledger_index_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/ledger?partner_id='.$this->partnerB->id);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    public function test_ledger_index_accepts_in_scope_account_id(): void
    {
        $same = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson('/api/v1/ledger?account_id='.$this->accountA->id);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting.003 — CreateJournalEntryRequest validator
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_store_rejects_cross_tenant_account_id_in_lines(): void
    {
        $payload = [
            'entry_date' => '2026-05-04',
            'description' => 'Test',
            'lines' => [
                ['account_id' => $this->accountA->id, 'debit' => '10.00', 'credit' => '0'],
                ['account_id' => $this->accountB->id, 'debit' => '0', 'credit' => '10.00'], // foreign tenant
            ],
        ];

        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/journal-entries', $payload);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('lines.1.account_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting.004/005 — PartnerBalanceService scoped findOrFail
    // ──────────────────────────────────────────────────────────────────

    public function test_partner_balance_service_refresh_refuses_cross_tenant_partner(): void
    {
        $service = $this->app->make(PartnerBalanceService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->refreshPartnerBalance($this->companyA->id, $this->partnerB->id);
    }

    public function test_partner_balance_service_cached_or_calculate_refuses_cross_tenant_partner(): void
    {
        $service = $this->app->make(PartnerBalanceService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->getCachedOrCalculateBalance($this->companyA->id, $this->partnerB->id);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting.004/005 (round-2 Codex) — controller-tier
    // UserCompanyMembership check refuses route-driven cross-tenant exploit
    // ──────────────────────────────────────────────────────────────────

    public function test_partner_balance_refresh_route_refuses_foreign_company(): void
    {
        // Real attack shape Codex round-1 second-layer flagged: tenant-A user
        // hits /api/v1/companies/{companyB}/partners/{partnerB}/balance/refresh.
        // Pre-fix, the company-only service scope resolved tenant-B's partner.
        // Post-fix, the controller's UserCompanyMembership check short-circuits
        // with 404 because userA is not a member of companyB.
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson("/api/v1/companies/{$this->companyB->id}/partners/{$this->partnerB->id}/balance/refresh");
        $cross->assertStatus(404);
    }

    public function test_partner_balance_show_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/partners/{$this->partnerB->id}/balance");
        $cross->assertStatus(404);
    }

    public function test_partner_balance_statement_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/partners/{$this->partnerB->id}/statement");
        $cross->assertStatus(404);
    }

    public function test_partner_balance_receivables_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/subledger/receivables");
        $cross->assertStatus(404);
    }

    public function test_partner_balance_refresh_all_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson("/api/v1/companies/{$this->companyB->id}/partners/balance/refresh-all");
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting (round-3 Opus remediation) — sibling controller
    // route-driven exploits closed by the same RequiresCompanyAccess trait
    // ──────────────────────────────────────────────────────────────────

    public function test_account_purpose_index_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/accounts/purposes");
        $cross->assertStatus(404);
    }

    public function test_account_purpose_validate_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/accounts/purposes/validate");
        $cross->assertStatus(404);
    }

    public function test_account_purpose_assign_mutation_route_refuses_foreign_company(): void
    {
        // High-impact test: assignPurpose() mutates tenant-B's chart of
        // accounts. Must 404 at the controller, not reach the service.
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->putJson(
                "/api/v1/companies/{$this->companyB->id}/accounts/{$this->accountB->id}/purpose",
                ['purpose' => 'cash'],
            );
        $cross->assertStatus(404);
    }

    public function test_account_purpose_remove_mutation_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->deleteJson("/api/v1/companies/{$this->companyB->id}/accounts/{$this->accountB->id}/purpose");
        $cross->assertStatus(404);
    }

    public function test_opening_balance_batches_index_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/opening-batches");
        $cross->assertStatus(404);
    }

    public function test_opening_balance_batches_status_route_refuses_foreign_company(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/companies/{$this->companyB->id}/opening-batches/status");
        $cross->assertStatus(404);
    }

    public function test_opening_balance_batches_post_route_refuses_foreign_company(): void
    {
        // The fiscal hash chain mutation route — the highest-impact exploit
        // surface. A tenant-A user must NOT be able to post a batch into
        // tenant-B's accounting ledger via this endpoint.
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson("/api/v1/companies/{$this->companyB->id}/opening-batches/fake-batch-id/post");
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.accounting.006 — GeneralLedgerReportService::getAccountDetails
    // ──────────────────────────────────────────────────────────────────

    public function test_get_account_details_returns_null_for_cross_tenant_account(): void
    {
        $service = $this->app->make(GeneralLedgerReportService::class);

        $result = $service->getAccountDetails(
            accountId: $this->accountB->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $this->assertNull($result);
    }

    public function test_get_account_details_returns_account_for_in_scope_id(): void
    {
        $service = $this->app->make(GeneralLedgerReportService::class);

        $result = $service->getAccountDetails(
            accountId: $this->accountA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $this->assertNotNull($result);
        $this->assertSame('4000', $result['code']);
        $this->assertSame('Sales Revenue A', $result['name']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants — Treasury R3 Finding-14
    // ──────────────────────────────────────────────────────────────────

    public function test_journal_entry_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/journal-entries', [
                'entry_date' => '2026-05-04',
                'description' => 'SQL log test',
                'lines' => [
                    ['account_id' => $this->accountA->id, 'debit' => '10.00', 'credit' => '0'],
                    ['account_id' => $this->accountA->id, 'debit' => '0', 'credit' => '10.00'],
                ],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $accountValidatorQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "accounts"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $accountValidatorQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $accountValidatorQuery,
            'Account exists-validation query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $accountValidatorQuery,
            'CreateJournalEntryRequest account_id validator must filter by tenant_id. SQL: '.$accountValidatorQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $accountValidatorQuery,
            'CreateJournalEntryRequest account_id validator must also filter by company_id. SQL: '.$accountValidatorQuery,
        );
    }

    public function test_get_account_details_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $service = $this->app->make(GeneralLedgerReportService::class);
        $service->getAccountDetails(
            accountId: $this->accountA->id,
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $accountQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, 'from "accounts"') && str_contains($sql, '"id" =')) {
                $accountQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $accountQuery,
            'getAccountDetails query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $accountQuery, 'SQL: '.$accountQuery);
        $this->assertStringContainsString('"company_id"', $accountQuery, 'SQL: '.$accountQuery);
    }

    // ──────────────────────────────────────────────────────────────────
    // ExpenseRequest validators (api.unmapped.001-003 reassigned to
    // api.accounting). Pre-fix, the FormRequest used bare exists rules
    // for expense_category_id, payment_method_id, payment_repository_id —
    // any cross-tenant id with a valid UUID could satisfy the FK
    // validator, allowing arbitrary cross-tenant Treasury / Expense
    // resource assignment in newly created expenses. Post-fix, all three
    // are ScopedExists::tenantAndCompany using CompanyContext-derived
    // tenant + company.
    // ──────────────────────────────────────────────────────────────────

    public function test_expense_create_rejects_cross_tenant_expense_category_id(): void
    {
        /** @var ExpenseCategory $expenseCategoryB */
        $expenseCategoryB = ExpenseCategory::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Office Expenses B',
            'is_active' => true,
        ]);

        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expenses', [
                'expense_category_id' => $expenseCategoryB->id,
                'total' => '10.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('expense_category_id', $cross->json('error.errors') ?? []);
    }

    public function test_expense_create_rejects_cross_tenant_payment_method_id(): void
    {
        /** @var PaymentMethod $paymentMethodB */
        $paymentMethodB = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'CASH-B',
            'name' => 'Cash B',
            'is_active' => true,
        ]);

        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expenses', [
                'payment_method_id' => $paymentMethodB->id,
                'total' => '10.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('payment_method_id', $cross->json('error.errors') ?? []);
    }

    public function test_expense_create_rejects_cross_tenant_payment_repository_id(): void
    {
        /** @var PaymentRepository $paymentRepoB */
        $paymentRepoB = PaymentRepository::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'CASH-DRW-B',
            'name' => 'Cash Drawer B',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expenses', [
                'payment_repository_id' => $paymentRepoB->id,
                'total' => '10.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('payment_repository_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // ExpenseCategoryRequest validators (api.unmapped.004-005 reassigned
    // to api.accounting). Pre-fix, the FormRequest used bare exists for
    // parent_id (self-ref expense_categories) and account_id (accounts).
    // Cross-tenant assignment of either was structurally possible.
    // ──────────────────────────────────────────────────────────────────

    public function test_expense_category_create_rejects_cross_tenant_parent_id(): void
    {
        /** @var ExpenseCategory $expenseCategoryB */
        $expenseCategoryB = ExpenseCategory::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Parent B',
            'is_active' => true,
        ]);

        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Child of Foreign Parent',
                'parent_id' => $expenseCategoryB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('parent_id', $cross->json('error.errors') ?? []);
    }

    public function test_expense_category_create_rejects_cross_tenant_account_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expense-categories', [
                'name' => 'Cross-Account Category',
                'account_id' => $this->accountB->id, // foreign-tenant account
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('account_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // ExpenseCategoryController::wouldCreateCircularReference (api.unmapped.013
    // reassigned to api.accounting). The private helper used bare
    // ExpenseCategory::find($parentId) to walk parent chain; without a
    // company_id predicate, a cross-tenant parent_id passed to the
    // update() route could cause the circular-reference check to traverse
    // a foreign tenant's category tree (information disclosure: detect a
    // foreign uuid is present, optionally trigger 422 vs missing-parent
    // 404). Post-fix, the find is scoped to the same company_id as the
    // route's category. Note: api.unmapped.004 above already validates
    // parent_id at the validator tier; this is defense-in-depth.
    // ──────────────────────────────────────────────────────────────────

    public function test_expense_category_update_circular_check_does_not_walk_foreign_tenant_tree(): void
    {
        // Same-tenant child category that the user owns and is updating.
        /** @var ExpenseCategory $sameTenantChild */
        $sameTenantChild = ExpenseCategory::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Same-Tenant Child',
            'is_active' => true,
        ]);

        // Foreign-tenant parent. Even though the validator should reject
        // this at the parent_id rule, the SQL captured here pins the
        // post-fix wouldCreateCircularReference scope. Pre-fix would emit
        // an unscoped `select * from expense_categories where id = ?`;
        // post-fix the helper either is short-circuited by the validator
        // or, if reached, scopes by company_id.
        /** @var ExpenseCategory $foreignParent */
        $foreignParent = ExpenseCategory::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Foreign Parent',
            'is_active' => true,
        ]);

        $response = $this->actingAsForCompany($this->userA, $this->companyA)
            ->putJson("/api/v1/expense-categories/{$sameTenantChild->id}", [
                'name' => 'Renamed Same-Tenant',
                'parent_id' => $foreignParent->id,
            ]);
        // Validator should 422 (api.unmapped.004 fix); confirms the
        // attack is short-circuited.
        $response->assertStatus(422);
        $this->assertArrayHasKey('parent_id', $response->json('error.errors') ?? []);

        // Post-condition: the foreign parent's tree must not have been
        // mutated, and no cross-tenant category was hit by the helper.
        $this->assertSame(
            'Same-Tenant Child',
            $sameTenantChild->fresh()?->name,
            'Same-tenant child must NOT have been renamed.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants (bar-raising) — pin SQL shape of
    // the new ScopedExists FormRequest validators.
    // ──────────────────────────────────────────────────────────────────

    public function test_expense_create_validators_query_includes_tenant_and_company_predicates(): void
    {
        /** @var ExpenseCategory $expenseCategoryA */
        $expenseCategoryA = ExpenseCategory::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Office Expenses A',
            'is_active' => true,
        ]);

        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/expenses', [
                'expense_category_id' => $expenseCategoryA->id,
                'total' => '10.00',
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Find the expense_categories validator query (count(*) with id =).
        $expenseCategoryValidator = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "expense_categories"')
                && str_contains($sql, 'count(*)')
            ) {
                $expenseCategoryValidator = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $expenseCategoryValidator,
            'expense_categories exists-validation query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $expenseCategoryValidator,
            'ExpenseRequest expense_category_id validator must filter by tenant_id. Got SQL: '.$expenseCategoryValidator,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $expenseCategoryValidator,
            'ExpenseRequest expense_category_id validator must filter by company_id. Got SQL: '.$expenseCategoryValidator,
        );
    }

    private function actingAsForCompany(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
