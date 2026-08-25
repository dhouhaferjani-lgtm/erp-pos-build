<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Exceptions\ExpensePaidWithoutRepositoryException;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\Treasury\DTOs\OpeningFloatIntent;
use App\Shared\Contracts\Treasury\RepositoryOpeningBalanceSeederInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W4-10 (campaign wave-4 report §W4-10, P1): an expense was born paid
 * (`is_paid = true`, `payment_repository_id = NULL`), settled against the
 * default GL cash account with NO repository movement, and `/pay` then refused
 * it — so GL cash fell while the drawer never moved, permanently.
 *
 * These tests pin: a cash-paid expense MUST name the repository it left, the
 * movement + GL credit land on THAT repository's own account, and a born-paid
 * expense with no repository is refused with a typed 422 unless the chosen
 * payment method is non-cash.
 */
final class ExpensePaidFromRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Account $cashAccount;

    private Account $expenseAccount;

    private PaymentRepository $drawer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Expense Repo Tenant',
            'slug' => 'expense-repo-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expense Repo Co',
            'legal_name' => 'Expense Repo Co SARL',
            'tax_id' => 'TAX-EXPREPO-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expense Admin',
            'email' => 'expense-repo-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashAccount = $this->account('53', 'Caisse', AccountType::Asset, SystemAccountPurpose::Cash);
        $this->account('512', 'Banque', AccountType::Asset, SystemAccountPurpose::Bank);
        $this->expenseAccount = $this->account('6', 'Charges', AccountType::Expense, SystemAccountPurpose::GeneralExpense);
        $this->account('401', 'Fournisseurs', AccountType::Liability, SystemAccountPurpose::SupplierPayable);
        $this->account('4456', 'TVA déductible', AccountType::Asset, SystemAccountPurpose::VatDeductible);

        $this->drawer = PaymentRepository::forceCreate([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-01',
            'name' => 'Caisse principale',
            'type' => RepositoryType::CashRegister,
            'account_id' => $this->cashAccount->id,
            'gl_account_id' => $this->cashAccount->id,
            'is_active' => true,
        ]);
    }

    /**
     * The W4-10 headline: a 200-float drawer pays a 5.000 expense and ends at
     * 195.000, with a movement and a GL credit on the drawer's own account.
     */
    public function test_a_cash_expense_paid_from_a_repository_moves_that_repository(): void
    {
        $this->seedFloat('200.000');

        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '5.000',
            'vendor_name' => 'Café du coin',
            'is_paid' => true,
            'payment_repository_id' => $this->drawer->id,
            'payment_date' => now()->toDateString(),
        ], $this->user);

        $posted = $this->service()->post($expense, $this->user);

        $this->assertSame('195.000', $this->drawer->fresh()?->balance);

        $movement = RepositoryMovement::query()
            ->where('source_type', MovementSourceType::Expense->value)
            ->where('source_id', $posted->id)
            ->firstOrFail();

        $this->assertSame(MovementDirection::Out, $movement->direction);
        $this->assertSame('5.000', $movement->amount);
        $this->assertSame($this->drawer->id, $movement->payment_repository_id);
        $this->assertNotNull($movement->journal_entry_id);

        $credit = JournalLine::query()
            ->where('journal_entry_id', $movement->journal_entry_id)
            ->where('account_id', $this->drawer->gl_account_id)
            ->firstOrFail();
        $this->assertSame('5.000', (string) $credit->credit);

        $debit = JournalLine::query()
            ->where('journal_entry_id', $movement->journal_entry_id)
            ->where('account_id', $this->expenseAccount->id)
            ->firstOrFail();
        $this->assertSame('5.000', (string) $debit->debit);
    }

    public function test_a_born_paid_expense_without_a_repository_is_refused(): void
    {
        $this->expectException(ExpensePaidWithoutRepositoryException::class);

        $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
        ], $this->user);
    }

    public function test_the_api_returns_a_typed_422_for_a_born_paid_expense_without_a_repository(): void
    {
        $this->user->givePermissionTo(['expenses.create', 'expenses.view']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/expenses', [
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => true,
        ]);

        $response->assertStatus(422);
        $this->assertSame('EXPENSE_PAID_WITHOUT_REPOSITORY', $response->json('code'));
        $this->assertSame(0, Document::query()->count());
    }

    public function test_a_non_cash_payment_method_may_be_paid_without_a_repository(): void
    {
        $card = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'is_cash_tender' => false,
            'is_active' => true,
        ]);

        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => true,
            'payment_method_id' => $card->id,
        ], $this->user);

        $this->assertNotNull($expense->id);
    }

    public function test_an_unpaid_expense_needs_no_repository(): void
    {
        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => false,
        ], $this->user);

        $this->assertNotNull($expense->id);
    }

    public function test_updating_an_expense_to_paid_without_a_repository_is_refused(): void
    {
        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => false,
        ], $this->user);

        $this->expectException(ExpensePaidWithoutRepositoryException::class);

        $this->service()->update($expense, ['is_paid' => true], $this->user);
    }

    private function seedFloat(string $amount): void
    {
        $seeder = app(RepositoryOpeningBalanceSeederInterface::class);

        DB::transaction(function () use ($seeder, $amount): void {
            $seeder->seed(new OpeningFloatIntent(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                repositoryId: $this->drawer->id,
                amount: $amount,
                currency: 'TND',
                batchId: (string) Str::uuid(),
                occurredAt: CarbonImmutable::now()->subDay(),
                journalEntryId: null,
                createdBy: $this->user->id,
            ));
        });
    }

    private function service(): ExpenseService
    {
        return app(ExpenseService::class);
    }

    private function account(string $code, string $name, AccountType $type, SystemAccountPurpose $purpose): Account
    {
        return Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
        ]);
    }
}
