<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

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
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
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
use Tests\Traits\AssertsApiValidation;

/**
 * Wave-E coverage for POST /payment-repositories/{id}/adjustments — Task 23,
 * the gated manual cash-count-variance / correction adjustment endpoint.
 * Writes a movement(adjustment) via the write port AND a balanced GL entry
 * (cash ↔ variance account), atomically, so the movement always carries a
 * non-null journal_entry_id.
 */
final class RepositoryAdjustmentTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    public function test_out_adjustment_decrements_balance_and_posts_balanced_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        // Balance decremented.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);

        $movementId = $response->json('data.movement_id');
        $this->assertIsString($movementId);

        // Exactly ONE movement, source_type=adjustment, reason_code=count_variance,
        // non-null journal_entry_id.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame($movementId, $movement->id);
        $this->assertSame('out', $movement->direction);
        $this->assertSame(0, bccomp((string) $movement->amount, '25.000', 3));
        $this->assertSame('count_variance', $movement->reason_code);
        $this->assertNotNull($movement->journal_entry_id);

        // Balanced GL entry: Dr variance expense (658) / Cr cash.
        $varianceExpenseAccount = $this->accountFor($user, $company, SystemAccountPurpose::PaymentToleranceExpense);
        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $this->assertSame('repository_adjustment', $entry->source_type);
        $debitLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($varianceExpenseAccount->id, $debitLine->account_id);
        $this->assertSame($glAccount->id, $creditLine->account_id);
        $totalDebit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $totalCredit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3));
        $this->assertSame('25.000', $totalDebit);
    }

    public function test_in_adjustment_increments_balance_and_posts_balanced_gl_entry(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'in',
                'amount' => '10.000',
                'reason_code' => 'correction',
                'reason_text' => 'Found extra cash during count.',
            ])
            ->assertCreated();

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('510.000', $freshRepo->balance);

        $movementId = $response->json('data.movement_id');
        $movement = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->where('id', $movementId)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('correction', $movement->reason_code);
        $this->assertNotNull($movement->journal_entry_id);

        // Balanced GL entry: Dr cash / Cr variance income (758).
        $varianceIncomeAccount = $this->accountFor($user, $company, SystemAccountPurpose::PaymentToleranceIncome);
        $entry = JournalEntry::query()->whereKey($movement->journal_entry_id)->with('lines')->firstOrFail();
        $debitLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->debit, '0', 3) > 0);
        $creditLine = $entry->lines->first(fn (JournalLine $line): bool => bccomp((string) $line->credit, '0', 3) > 0);
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($glAccount->id, $debitLine->account_id);
        $this->assertSame($varianceIncomeAccount->id, $creditLine->account_id);
        $totalDebit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $totalCredit = $entry->lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 3));
    }

    public function test_missing_reason_text_returns_422(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                // reason_text omitted
            ])
            ->assertStatus(422);

        $this->assertApiValidationErrors($response, ['reason_text']);

        // No money moved.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    public function test_route_requires_treasury_adjust_permission(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.view']); // no treasury.adjust
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(403);

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    /**
     * Audit fix N5: a malformed (non-UUID) `{repository}` path param must 404,
     * not 500. On sqlite (the fast driver used by this suite) this assertion
     * passes even WITHOUT the `Str::isUuid()` guard — sqlite is typeless, so
     * `findOrFail()`'s `WHERE id = 'not-a-uuid'` simply matches no row and
     * throws `ModelNotFoundException` (404) regardless. The guard is only
     * load-bearing on Postgres, where the same malformed literal in a uuid
     * column comparison raises `22P02` (invalid input syntax) → HTTP 500
     * without it. See `.superpowers/sdd/audit-fix-3-report.md` for the pgsql
     * before/after evidence proving this test is genuinely red before the fix
     * and green after, on that driver.
     */
    public function test_malformed_repository_id_returns_404_not_500(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/payment-repositories/not-a-uuid/adjustments', [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertStatus(404);
    }

    public function test_adjustment_movement_writes_an_audit_event(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'theft_loss',
                'reason_text' => 'Suspected till theft.',
            ])
            ->assertCreated();

        $movementId = $response->json('data.movement_id');

        $auditEventCount = AuditEvent::where('aggregate_id', $movementId)
            ->where('event_type', 'treasury.repository.movement_recorded')
            ->count();
        $this->assertSame(1, $auditEventCount);
    }

    /**
     * Audit fix 4 (K2): the Tunisia chart-of-accounts seeder now wires the
     * 658/758 "payment tolerance" purposes (see
     * TunisiaChartOfAccountsSeeder::getAccountsDefinition, codes 6580/7580),
     * so a plain `seedForCompany()` is sufficient for the 'out' direction —
     * no missing-purpose 422 should surface.
     */
    public function test_out_adjustment_succeeds_with_only_tunisia_seeder_no_manual_tolerance_accounts(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ])
            ->assertCreated();

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);
    }

    /**
     * Audit fix 4 (K2): defense in depth for any tenant whose chart was seeded
     * before this fix (or any custom chart that never assigned the 658/758
     * purposes). Previously `Account::findByPurposeOrFail` threw a bare
     * `RuntimeException` here, uncaught → HTTP 500. Must now be a translated
     * 422 with no money moved and no GL entry created.
     */
    public function test_out_adjustment_returns_422_when_chart_lacks_payment_tolerance_expense_purpose(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        // Deliberately do NOT run a chart-of-accounts seeder — only create the
        // one account the repository itself needs (Cash). No account carries
        // the PaymentToleranceExpense purpose.
        $cashAccount = Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => '53',
            'name' => 'Caisse',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));

        // No money moved, no movement/GL row created.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);

        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->count();
        $this->assertSame(0, $movements);
    }

    /**
     * Same defense-in-depth guarantee for the 'in' direction / income purpose.
     */
    public function test_in_adjustment_returns_422_when_chart_lacks_payment_tolerance_income_purpose(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);

        $cashAccount = Account::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'code' => '53',
            'name' => 'Caisse',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'in',
                'amount' => '10.000',
                'reason_code' => 'correction',
                'reason_text' => 'Found extra cash during count.',
            ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error'));

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
    }

    /**
     * W-5b Option B (owner ruling 2026-08-05): the interactive adjustment
     * endpoint is an INTERACTIVE writer (MovementIntent::$allowNegative left
     * at its default false) — an outflow that would drive a cash_register
     * below zero must be refused end-to-end with the typed 422 envelope, not
     * just at the service-unit level. GL entry + movement are both inside
     * the same transaction, so a refusal must roll back the JE too.
     */
    public function test_out_adjustment_returns_422_insufficient_repository_balance_and_writes_nothing(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['treasury.adjust', 'treasury.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $glAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '10.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glAccount->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/payment-repositories/{$repo->id}/adjustments", [
                'direction' => 'out',
                'amount' => '25.000',
                'reason_code' => 'count_variance',
                'reason_text' => 'Till was short at close.',
            ]);

        $response->assertStatus(422);
        $this->assertSame('INSUFFICIENT_REPOSITORY_BALANCE', $response->json('error.code'));
        $this->assertSame('10.000', $response->json('error.available'));
        $this->assertSame('25.000', $response->json('error.requested'));
        $this->assertSame('-15.000', $response->json('error.resulting_balance'));
        $this->assertSame($repo->id, $response->json('error.repository_id'));
        $this->assertIsString($response->json('error.message'));

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('10.000', $freshRepo->balance);

        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'adjustment')
            ->count();
        $this->assertSame(0, $movements);

        $journalEntries = JournalEntry::query()
            ->where('company_id', $company->id)
            ->where('source_type', 'repository_adjustment')
            ->count();
        $this->assertSame(0, $journalEntries);
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
