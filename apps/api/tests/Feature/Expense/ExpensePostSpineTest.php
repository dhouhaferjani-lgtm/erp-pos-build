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
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Wave-D spine coverage for expense posting:
 *  - a PAID expense converges onto the treasury write port (one movement + cash credit).
 *  - an UNPAID expense books an AP liability and moves NO money (bug fix).
 *  - the GL post and the movement are atomic (both roll back together).
 */
final class ExpensePostSpineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * (a) A paid expense post decrements the repository balance, writes exactly
     * ONE movement row (source_type=expense, direction=out), and the GL entry's
     * credit line is the CASH account.
     */
    public function test_paid_expense_posts_one_movement_and_credits_cash(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
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

        // Balance decremented via the port: 500.000 - 150.000 = 350.000
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('350.000', $freshRepo->balance);

        // Exactly ONE movement row, source_type=expense, direction=out.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertSame($expense->id, $movement->source_id);

        // GL credit line is the CASH account.
        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);
        $creditLine = $this->creditLineFor($expense->id);
        $this->assertNotNull($creditLine);
        $this->assertSame($cashAccount->id, $creditLine->account_id);
        // Movement points at the posted JE.
        $this->assertSame($creditLine->journal_entry_id, $movement->journal_entry_id);
    }

    /**
     * (b) An unpaid expense post writes NO movement, leaves the repository
     * balance UNCHANGED, and the GL credit line is the AP-liability account
     * (NOT cash).
     */
    public function test_unpaid_expense_books_ap_liability_and_moves_no_money(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
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
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/expenses/{$expense->id}/post")
            ->assertOk();

        // No money moved: balance unchanged, no movement row.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
        $this->assertSame(0, DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'expense')
            ->count());

        // GL credit line is the AP-liability account, NOT cash.
        $apAccount = $this->accountFor($user, $company, SystemAccountPurpose::SupplierPayable);
        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);
        $creditLine = $this->creditLineFor($expense->id);
        $this->assertNotNull($creditLine);
        $this->assertSame($apAccount->id, $creditLine->account_id);
        $this->assertNotSame($cashAccount->id, $creditLine->account_id);
        // Vendor partner is carried on the AP line for the subledger.
        $this->assertSame($expense->partner_id, $creditLine->partner_id);
    }

    /**
     * (c) Atomicity: when the movement leg fails (frozen repository) AFTER the GL
     * entry has been posted in-transaction, the whole post rolls back — no
     * movement, no journal entry, balance unchanged, expense stays Draft.
     */
    public function test_movement_failure_rolls_back_the_gl_post(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['expenses.post', 'expenses.view']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'frozen_at' => now(),
            'frozen_reason' => 'reconciliation',
        ]);

        $expense = $this->makeExpense($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        ExpenseMetadata::create([
            'document_id' => $expense->id,
            'is_paid' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        try {
            app(ExpenseService::class)->post($expense, $user);
            $this->fail('Expected RepositoryFrozenException — the frozen repository must reject the movement.');
        } catch (RepositoryFrozenException) {
            // expected
        }

        // Everything rolled back together.
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('500.000', $freshRepo->balance);
        $this->assertSame(0, DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)->count());
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)->count());
        $freshExpense = $expense->fresh();
        $this->assertNotNull($freshExpense);
        $this->assertSame(DocumentStatus::Draft, $freshExpense->status);
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

    private function creditLineFor(string $expenseId): ?JournalLine
    {
        $entry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expenseId)
            ->with('lines')
            ->first();

        return $entry?->lines->first(fn ($line): bool => bccomp((string) $line->credit, '0', 3) > 0);
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
