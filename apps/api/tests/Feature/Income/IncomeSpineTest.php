<?php

declare(strict_types=1);

namespace Tests\Feature\Income;

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
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Income\Application\Services\IncomeService;
use App\Modules\Income\Domain\IncomeMetadata;
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
 * Wave-D spine coverage for income posting (Task 17 — the mirror of
 * ExpensePostSpineTest):
 *  - a RECEIVED income converges onto the treasury write port (one movement,
 *    balance increases exactly once — not twice).
 *  - the GL post and the movement are atomic (both roll back together).
 */
final class IncomeSpineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A received income post increments the repository balance exactly ONCE,
     * writes exactly ONE movement row (source_type=income, direction=in)
     * linked to the posted JE, and the income document keeps its usual
     * post-state (document_number assigned, status Posted).
     */
    public function test_received_income_posts_one_movement_and_increments_balance_once(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view', 'income.create']);
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => '500.000',
            'currency' => 'TND',
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

        // Balance incremented via the port ONCE: 500.000 + 150.000 = 650.000
        // (not 800.000, which would indicate double-counting).
        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('650.000', $freshRepo->balance);

        // Exactly ONE movement row, source_type=income, direction=in.
        $movements = DB::table('repository_movements')
            ->where('payment_repository_id', $repo->id)
            ->where('source_type', 'income')
            ->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->direction);
        $this->assertSame($income->id, $movement->source_id);

        // GL debit line is the CASH account.
        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);
        $debitLine = $this->debitLineFor($income->id);
        $this->assertNotNull($debitLine);
        $this->assertSame($cashAccount->id, $debitLine->account_id);
        // Movement points at the posted JE.
        $this->assertSame($debitLine->journal_entry_id, $movement->journal_entry_id);

        $freshIncome = $income->fresh();
        $this->assertNotNull($freshIncome);
        $this->assertSame(DocumentStatus::Posted, $freshIncome->status);
        $this->assertStringStartsWith('INC-', $freshIncome->document_number);
    }

    /**
     * Atomicity: when the movement leg fails (frozen repository) AFTER the GL
     * entry has been posted in-transaction, the whole post rolls back — no
     * movement, no journal entry, balance unchanged, income stays Draft.
     */
    public function test_movement_failure_rolls_back_the_gl_post(): void
    {
        [$user, $company] = $this->makeUserWithPermissions(['income.post', 'income.view', 'income.create']);
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

        $income = $this->makeIncome($company, $user, ['total' => '150.000', 'currency' => 'TND']);
        IncomeMetadata::create([
            'document_id' => $income->id,
            'is_received' => true,
            'payment_repository_id' => $repo->id,
            'payment_date' => now()->toDateString(),
        ]);

        try {
            app(IncomeService::class)->post($income, $user);
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
            ->where('source_type', 'income')->where('source_id', $income->id)->count());
        $freshIncome = $income->fresh();
        $this->assertNotNull($freshIncome);
        $this->assertSame(DocumentStatus::Draft, $freshIncome->status);
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

    private function debitLineFor(string $incomeId): ?JournalLine
    {
        $entry = JournalEntry::query()
            ->where('source_type', 'income')
            ->where('source_id', $incomeId)
            ->with('lines')
            ->first();

        return $entry?->lines->first(fn ($line): bool => bccomp((string) $line->debit, '0', 3) > 0);
    }

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
