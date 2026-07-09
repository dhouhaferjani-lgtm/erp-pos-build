<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Domain\Enums\ExpenseKind;
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

/**
 * Wave-D coverage for POST /expenses/{id}/pay — the settlement endpoint that
 * closes the unpaid-expense AP loop opened by Task 14 (post() booking
 * Dr expense / Cr SupplierPayable for an unpaid expense).
 */
final class ExpenseSettlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Settling a Posted+unpaid expense decrements the repository balance,
     * writes exactly ONE movement whose idempotency_key ends `:settlement`,
     * the GL entry debits AP-liability and credits Cash, and metadata flips
     * to is_paid=true with paid_at set.
     */
    public function test_settle_posted_unpaid_expense_pays_down_the_ap_liability(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        $response->assertJsonPath('data.metadata.is_paid', true);
        $this->assertNotNull($response->json('data.metadata.paid_at'));

        // Repository balance decremented by the settled amount.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('350.000', $freshRepo->balance);

        // Exactly ONE settlement movement.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertStringEndsWith(':settlement', (string) $movement->idempotency_key);

        // GL: Dr AP-liability / Cr Cash on the settlement entry.
        $apAccount = $this->accountFor($user, $company, SystemAccountPurpose::SupplierPayable);
        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);
        $settlementEntry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $debitLine = $settlementEntry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $settlementEntry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($apAccount->id, $debitLine->account_id);
        $this->assertSame($cashAccount->id, $creditLine->account_id);

        // Metadata reflects the settlement.
        $metadata = ExpenseMetadata::query()->where('document_id', $expense->id)->firstOrFail();
        $this->assertTrue($metadata->is_paid);
        $this->assertNotNull($metadata->paid_at);
        $this->assertSame($repo->id, $metadata->payment_repository_id);
    }

    /**
     * Audit fix N5: a malformed (non-UUID) `{id}` path param on
     * POST /expenses/{id}/pay must 404, not 500. On sqlite (the fast driver
     * used by this suite) this assertion passes even WITHOUT the
     * `Str::isUuid()` guard — sqlite is typeless, so `firstOrFail()`'s
     * `WHERE id = 'not-a-uuid'` simply matches no row and throws
     * `ModelNotFoundException` (404) regardless. The guard is only
     * load-bearing on Postgres, where the same malformed literal in a uuid
     * column comparison raises `22P02` (invalid input syntax) → HTTP 500
     * without it. See `.superpowers/sdd/audit-fix-3-report.md` for the pgsql
     * before/after evidence proving this test is genuinely red before the fix
     * and green after, on that driver.
     */
    public function test_pay_with_malformed_expense_id_returns_404_not_500(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/expenses/not-a-uuid/pay', [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(404);
    }

    /**
     * Settling an already-paid expense (paid at post time, no AP was ever
     * booked) returns 422 — there is nothing to settle.
     */
    public function test_settle_already_paid_expense_returns_422(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    /**
     * Settling twice (retry, e.g. a client resubmit) is idempotent: only ONE
     * settlement movement is ever written and the repository balance moves
     * exactly once.
     */
    public function test_settle_twice_is_idempotent(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $payload = [
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", $payload)
            ->assertOk();

        // Retry.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", $payload)
            ->assertOk();

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('350.000', $freshRepo->balance);

        $this->assertSame(1, DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->count());
    }

    /**
     * A no-partner expense (petty-cash / anonymous vendor — documents.partner_id
     * is nullable) settles cleanly: Task 14 booked its AP line with a NULL
     * partner, and the settlement JE mirrors that with the SAME null partner.
     * Previously this passed null into the non-null-partner supplier-payment
     * helper and 500'd with a TypeError (Fix 1).
     */
    public function test_settle_null_partner_expense_succeeds(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        // No partner — the anonymous-vendor / petty-cash case.
        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND', 'partner_id' => null]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertOk();

        $response->assertJsonPath('data.metadata.is_paid', true);

        // Balance decremented.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('350.000', $freshRepo->balance);

        // Exactly ONE settlement movement.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertStringEndsWith(':settlement', (string) $movement->idempotency_key);

        // GL: Dr AP-liability (partner NULL) / Cr Cash, balanced.
        $apAccount = $this->accountFor($user, $company, SystemAccountPurpose::SupplierPayable);
        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);
        $settlementEntry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $debitLine = $settlementEntry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $settlementEntry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($apAccount->id, $debitLine->account_id);
        $this->assertNull($debitLine->partner_id);
        $this->assertSame($cashAccount->id, $creditLine->account_id);
        // Entry balances: total debits == total credits.
        $totalDebit = $settlementEntry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $totalCredit = $settlementEntry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3));
        $this->assertSame('150.000', $totalDebit);
    }

    /**
     * A linked-cost expense NEVER booked an AP liability — its capitalization
     * entry credits Cash directly. Settling it via /pay would fabricate a
     * phantom AP reversal and double-decrement cash, so it is rejected with 422
     * and moves no money and posts no settlement JE (Fix 2).
     */
    public function test_settle_linked_cost_expense_returns_422_and_moves_no_money(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.pay', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        // A Posted, unpaid, linked-cost expense. Built directly in Posted state:
        // the settle() kind-guard rejects before any GL/treasury work, so the
        // heavyweight linked-cost capitalization posting is not required here.
        $expense = $this->makeExpense($company, $user, [
            'total' => '150.000',
            'currency' => 'TND',
            'status' => DocumentStatus::Posted,
            'document_number' => 'EXP-2026-000999',
        ]);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
            'expense_kind' => ExpenseKind::LinkedCost,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // No money moved.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);

        // No settlement movement.
        $this->assertSame(0, DB::table('repository_movements')
            ->where('source_id', $expense->id)
            ->count());

        // No settlement journal entry.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'expense_settlement')
            ->where('source_id', $expense->id)
            ->count());

        // Metadata untouched — still unpaid.
        $metadata = ExpenseMetadata::query()->where('document_id', $expense->id)->firstOrFail();
        $this->assertFalse($metadata->is_paid);
    }

    /**
     * The route requires `can:expenses.pay` — a user without it gets 403.
     */
    public function test_pay_route_requires_expenses_pay_permission(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']); // no expenses.pay
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => false,
            'payment_repository_id' => null,
            'payment_date' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'payment_repository_id' => $repo->id,
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function accountFor(User $user, Company $company, SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
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
