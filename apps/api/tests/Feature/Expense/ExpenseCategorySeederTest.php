<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

#[UsesFrozenSeederFixture]
final class ExpenseCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_categories_have_valid_gl_accounts(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]);

        // Seed Tunisia COA so class-6 accounts exist for this company
        $coaSeeder = new TunisiaChartOfAccountsSeeder;
        $coaSeeder->run($company->id, $company->tenant_id);

        // Seed expense categories for the company
        app(ExpenseCategorySeeder::class)->seedForCompany($company);

        $categories = ExpenseCategory::where('company_id', $company->id)->get();

        $this->assertGreaterThanOrEqual(4, $categories->count());

        foreach ($categories as $cat) {
            $this->assertNotNull($cat->account_id, "Category '{$cat->name}' has a null account_id");
            $this->assertDatabaseHas('accounts', [
                'id' => $cat->account_id,
                'company_id' => $company->id,
            ]);
        }
    }

    public function test_tunisian_categories_use_the_606_family_instead_of_the_general_expense_fallback(): void
    {
        [, $company] = $this->makeUserWithPermissions([]);

        (new TunisiaChartOfAccountsSeeder)->run($company->id, $company->tenant_id);
        app(ExpenseCategorySeeder::class)->seedForCompany($company);

        $this->assertCategoryUsesAccountCode($company, 'Eau & Électricité', '6061');
        $this->assertCategoryUsesAccountCode($company, 'Fournitures administratives', '6064');
        $this->assertCategoryUsesAccountCode($company, 'Loyer', '613');
    }

    public function test_french_companies_receive_categories_on_the_pcg_accounts(): void
    {
        [, $company] = $this->makeUserWithPermissions([], 'FR');

        (new FranceChartOfAccountsSeeder)->run($company->id, $company->tenant_id);
        app(ExpenseCategorySeeder::class)->seedForCompany($company);

        $this->assertCategoryUsesAccountCode($company, 'Loyer', '613');
        $this->assertCategoryUsesAccountCode($company, 'Transport', '624');
        $this->assertCategoryUsesAccountCode($company, 'Eau & Électricité', '6061');
        // The catch-all resolves through the GeneralExpense purpose, which the
        // French chart only gained in this lane (register E-1).
        $this->assertCategoryUsesAccountCode($company, 'Fournitures & Divers', '628');
    }

    public function test_generic_companies_receive_english_categories_on_the_generic_chart(): void
    {
        [, $company] = $this->makeUserWithPermissions([], 'GB');

        (new GenericChartOfAccountsSeeder)->run($company->id, $company->tenant_id);
        app(ExpenseCategorySeeder::class)->seedForCompany($company);

        $this->assertCategoryUsesAccountCode($company, 'Utilities', '6130');
        $this->assertCategoryUsesAccountCode($company, 'Office Supplies', '6170');
        $this->assertCategoryUsesAccountCode($company, 'Other', '6280');
    }

    private function assertCategoryUsesAccountCode(Company $company, string $name, string $expectedCode): void
    {
        $category = ExpenseCategory::where('company_id', $company->id)->where('name', $name)->first();
        $this->assertNotNull($category, "Expected expense category '{$name}'");

        $code = Account::query()->whereKey($category->account_id)->value('code');
        $this->assertSame($expectedCode, $code, "Category '{$name}' should book to account {$expectedCode}");
    }

    public function test_seeder_is_idempotent(): void
    {
        [$user, $company] = $this->makeUserWithPermissions([]);

        $coaSeeder = new TunisiaChartOfAccountsSeeder;
        $coaSeeder->run($company->id, $company->tenant_id);

        $seeder = app(ExpenseCategorySeeder::class);
        $seeder->seedForCompany($company);
        $seeder->seedForCompany($company);

        $count = ExpenseCategory::where('company_id', $company->id)->count();
        $this->assertGreaterThanOrEqual(4, $count);
        // Count must be the same after two runs (no duplicates)
        $seeder->seedForCompany($company);
        $this->assertSame($count, ExpenseCategory::where('company_id', $company->id)->count());
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Company}
     */
    private function makeUserWithPermissions(array $permissions, string $countryCode = 'TN'): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Parapharmacy',
            'legal_name' => 'Test Parapharmacy SARL',
            'tax_id' => 'TN9999999TT',
            'country_code' => $countryCode,
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
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
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
