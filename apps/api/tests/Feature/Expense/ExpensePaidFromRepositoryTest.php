<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Exceptions\ExpensePaidWithoutRepositoryException;
use App\Modules\Expense\Domain\ExpenseMetadata;
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
        $this->seedFloat($this->drawer, '200.000');

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

    /**
     * gate r1 F-4 — the per-till GL credit needs a fixture that can DISCRIMINATE.
     * The headline case above links the drawer to the Cash PURPOSE account, so
     * the new code and dev's pre-change code resolve to the same account and the
     * assertion passes either way. Here the till carries its own `5311`: revert
     * GeneralLedgerService's repository-account branch and this goes red.
     */
    public function test_the_credit_lands_on_the_tills_own_account_not_the_cash_purpose_account(): void
    {
        $boutiqueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5311',
            'name' => 'Caisse boutique',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $boutique = PaymentRepository::forceCreate([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-02',
            'name' => 'Caisse boutique',
            'type' => RepositoryType::CashRegister,
            'account_id' => $boutiqueAccount->id,
            'gl_account_id' => $boutiqueAccount->id,
            'is_active' => true,
        ]);

        $this->seedFloat($boutique, '500.000');

        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '5.000',
            'vendor_name' => 'Café du coin',
            'is_paid' => true,
            'payment_repository_id' => $boutique->id,
            'payment_date' => now()->toDateString(),
        ], $this->user);

        $posted = $this->service()->post($expense, $this->user);

        $movement = RepositoryMovement::query()
            ->where('source_type', MovementSourceType::Expense->value)
            ->where('source_id', $posted->id)
            ->firstOrFail();

        $this->assertSame(
            '5.000',
            (string) JournalLine::query()
                ->where('journal_entry_id', $movement->journal_entry_id)
                ->where('account_id', $boutiqueAccount->id)
                ->firstOrFail()
                ->credit,
            'The credit must land on the till\'s OWN account (5311).'
        );
        $this->assertSame(
            0,
            JournalLine::query()
                ->where('journal_entry_id', $movement->journal_entry_id)
                ->where('account_id', $this->cashAccount->id)
                ->count(),
            'The Cash PURPOSE account (53) must carry nothing for a till that has its own account.'
        );
        $this->assertSame('495.000', $boutique->fresh()?->balance);
    }

    /**
     * gate r1 F-2 — the guard has to hold at POST, not only at create/update.
     * A Draft row carrying `is_paid = true, payment_repository_id = NULL` is
     * reachable from any writer that is not create()/update(); before this guard
     * posting it produced `Cr 53 45.000` with zero movements (gate PROBE C).
     */
    public function test_posting_a_draft_that_is_paid_with_no_repository_is_refused(): void
    {
        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => false,
        ], $this->user);

        // Bypass create/update entirely — the shape a legacy row, a seeder or an
        // importer can leave behind.
        ExpenseMetadata::query()
            ->where('document_id', $expense->id)
            ->update(['is_paid' => true, 'payment_repository_id' => null]);

        $this->expectException(ExpensePaidWithoutRepositoryException::class);

        $this->service()->post($expense->fresh(['expenseMetadata']), $this->user);
    }

    /**
     * gate r1 F-3 — the sanctioned non-cash carve-out must not reproduce the very
     * shape W4-10 refuses. Before the fix a CARD-paid expense credited
     * `53 Caisse` and moved no till (PROBE F: `credit53=45 credit512=0
     * movements=0`), silently breaking the Σ-tills == GL-cash equality W4-2 has
     * just established — and invisibly to `treasury:reconcile`, because there is
     * no movement to check.
     */
    public function test_a_non_cash_expense_credits_the_methods_own_account_and_never_cash(): void
    {
        $cardAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5115',
            'name' => 'Cartes bancaires à encaisser',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        $card = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CARD',
            'name' => 'Carte bancaire',
            'is_physical' => false,
            'is_cash_tender' => false,
            'default_account_id' => $cardAccount->id,
            'is_active' => true,
        ]);

        $expense = $this->service()->create([
            'company_id' => $this->company->id,
            'total' => '45.000',
            'vendor_name' => 'Fournitures',
            'is_paid' => true,
            'payment_method_id' => $card->id,
            'payment_date' => now()->toDateString(),
        ], $this->user);

        $posted = $this->service()->post($expense, $this->user);

        $entryId = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $posted->id)
            ->firstOrFail()
            ->id;

        $this->assertSame(
            '45.000',
            (string) JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $cardAccount->id)
                ->firstOrFail()
                ->credit,
            'A card-paid expense settles through the method\'s own account.'
        );
        $this->assertSame(
            0,
            JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $this->cashAccount->id)
                ->count(),
            '53 Caisse must carry nothing: a card payment did not come out of a drawer.'
        );
        $this->assertSame(
            0,
            RepositoryMovement::query()->where('source_id', $posted->id)->count(),
            'No repository was named, so no till moves — that is the carve-out, and it is now honest.'
        );
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

        $this->service()->update($expense, ['is_paid' => true]);
    }

    /**
     * Give a till its opening float through the sanctioned W4-2 port.
     *
     * gate r1 F-12: the port now asserts that `batchId` names a REAL
     * opening-balance batch of this tenant+company, so the fixture posts one
     * rather than inventing a UUID — an opening movement with no document
     * behind it is exactly what document-per-action forbids.
     */
    private function seedFloat(PaymentRepository $repository, string $amount): void
    {
        $batch = OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Accounting,
            'name' => 'Fixture opening '.$repository->code,
            'cutover_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
            'status' => OpeningBatchStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        $seeder = app(RepositoryOpeningBalanceSeederInterface::class);

        DB::transaction(function () use ($seeder, $repository, $amount, $batch): void {
            $seeder->seed(new OpeningFloatIntent(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                repositoryId: $repository->id,
                amount: $amount,
                currency: 'TND',
                batchId: $batch->id,
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
