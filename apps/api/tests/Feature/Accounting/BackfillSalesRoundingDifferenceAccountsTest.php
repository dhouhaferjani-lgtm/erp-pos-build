<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

/**
 * W-6 D1a — `2026_08_05_120000_backfill_sales_rounding_difference_accounts`.
 *
 * The seeders now emit PCG 658/758 (`6581` / `7581`), but a seeder only runs for
 * NEW companies: every EXISTING France/Generic company would refuse to post the
 * first invoice whose per-line tax truncation leaves a positive residual. This
 * migration closes that, and because pushing to `origin/dev` auto-runs
 * `tenants:migrate` on staging it has to be safe on every chart shape and safe to
 * re-run. Each guard is pinned below.
 *
 * The migration is invoked directly rather than through `artisan migrate` because
 * `RefreshDatabase` has already run it against the (empty) schema; these cases
 * build the pre-migration chart shapes by hand and then apply it.
 */
#[UsesFrozenSeederFixture]
final class BackfillSalesRoundingDifferenceAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Backfill Tenant',
            'slug' => 'backfill-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);
    }

    public function test_it_gives_an_existing_french_company_the_rounding_pair(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripRoundingPair($company->id);

        $this->runBackfill();

        $expense = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense);
        $income = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome);

        $this->assertNotNull($expense);
        $this->assertNotNull($income);
        $this->assertSame('6581', $expense->code);
        $this->assertSame('7581', $income->code);

        // Filed under the PCG "autres charges/produits de gestion courante" groups.
        $this->assertSame(
            Account::query()->where('company_id', $company->id)->where('code', '65')->value('id'),
            $expense->parent_id,
        );
        $this->assertSame(
            Account::query()->where('company_id', $company->id)->where('code', '75')->value('id'),
            $income->parent_id,
        );
    }

    public function test_it_gives_an_existing_generic_company_the_rounding_pair(): void
    {
        $company = $this->company('MA', 'MAD');
        (new GenericChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripRoundingPair($company->id);

        $this->runBackfill();

        $this->assertNotNull(Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense));
        $this->assertNotNull(Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome));
    }

    /**
     * Tunisia is a NO-OP: `4375` already absorbs the residual and
     * `residualPlan()` prefers it, so the pair would be dead rows.
     */
    public function test_it_is_a_no_op_for_a_company_that_already_has_a_timbre_account(): void
    {
        $company = $this->company('TN', 'TND');
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $before = Account::query()->where('company_id', $company->id)->count();

        $this->runBackfill();

        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense));
        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome));
        $this->assertSame($before, Account::query()->where('company_id', $company->id)->count());
    }

    public function test_it_skips_a_company_with_no_chart_at_all(): void
    {
        $company = $this->company('FR', 'EUR');

        $this->runBackfill();

        $this->assertSame(0, Account::query()->where('company_id', $company->id)->count());
    }

    /**
     * THE EDGE THE GATE NAMED. A company that already owns a user-created account
     * at `6581` / `7581` cannot receive a duplicate (`accounts_company_code_unique`)
     * and must not be left refusing — the purpose is mapped ONTO the existing
     * account when its type is compatible and it carries no purpose of its own.
     */
    public function test_it_maps_the_purpose_onto_a_user_created_account_at_the_preferred_code(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripRoundingPair($company->id);

        $userExpense = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '6581',
            'name' => 'Frais divers (compte utilisateur)',
            'type' => 'expense',
            'is_active' => true,
        ]);

        $this->runBackfill();

        $mapped = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense);
        $this->assertNotNull($mapped);
        $this->assertSame($userExpense->id, $mapped->id, 'the existing account is claimed, not duplicated');
        $this->assertSame('Frais divers (compte utilisateur)', $mapped->name, 'the user keeps their name');
        $this->assertSame(1, Account::query()->where('company_id', $company->id)->where('code', '6581')->count());
    }

    /**
     * The preferred code is taken by an account of the WRONG type, or one that
     * already carries another system purpose: claiming it would corrupt the chart,
     * so the pair lands on the next free code in the series instead.
     */
    public function test_it_falls_back_to_the_next_free_code_when_the_preferred_one_is_unusable(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripRoundingPair($company->id);

        // Wrong type for an expense purpose.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '6581',
            'name' => 'Mis-typed account',
            'type' => 'revenue',
            'is_active' => true,
        ]);
        // Right type, but already spoken for by another system purpose. Built by
        // RE-CODING the chart's own breakage account rather than inserting a second
        // one, because `accounts` is unique on (company_id, system_purpose).
        Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::VoucherBreakageIncome->value)
            ->update(['code' => '7581']);

        $this->runBackfill();

        $expense = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense);
        $income = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome);

        $this->assertNotNull($expense);
        $this->assertNotNull($income);
        $this->assertSame('6582', $expense->code);
        $this->assertSame('7582', $income->code);
        $this->assertSame(AccountType::Expense, $expense->type);
        $this->assertSame(AccountType::Revenue, $income->type);
    }

    /**
     * `tenants:migrate` runs on every push to `origin/dev`; a second application
     * must change nothing.
     */
    public function test_it_is_idempotent(): void
    {
        $company = $this->company('FR', 'EUR');
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripRoundingPair($company->id);

        $this->runBackfill();
        $afterFirst = Account::query()->where('company_id', $company->id)->count();
        $expenseId = Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense)?->id;

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($afterFirst, Account::query()->where('company_id', $company->id)->count());
        $this->assertSame($expenseId, Account::findByPurpose($company->id, SystemAccountPurpose::SalesRoundingDifferenceExpense)?->id);
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_05_120000_backfill_sales_rounding_difference_accounts.php');
        $migration->up();
    }

    /**
     * Recreate the PRE-migration chart: the seeders now emit the pair, so it has to
     * be removed to reproduce an existing company's shape.
     */
    private function stripRoundingPair(string $companyId): void
    {
        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::SalesRoundingDifferenceExpense->value,
                SystemAccountPurpose::SalesRoundingDifferenceIncome->value,
            ])
            ->delete();
    }

    private function company(string $countryCode, string $currency): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Backfill Co '.Str::random(5),
            'legal_name' => 'Backfill Co SARL',
            'tax_id' => 'TAX-BF-'.uniqid(),
            'country_code' => $countryCode,
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => $currency,
            'status' => CompanyStatus::Active,
        ]);
    }
}
