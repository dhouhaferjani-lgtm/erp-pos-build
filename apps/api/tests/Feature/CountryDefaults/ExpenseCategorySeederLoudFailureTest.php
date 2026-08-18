<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

#[UsesFrozenSeederFixture]
final class ExpenseCategorySeederLoudFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholly_absent_chart_fails_before_any_category_is_written(): void
    {
        $company = $this->company('ZZ');

        try {
            app(ExpenseCategorySeeder::class)->seedForCompany($company);
            self::fail('A wholly absent chart must fail loudly.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('chart of accounts', $exception->getMessage());
        }

        self::assertSame(0, ExpenseCategory::query()->where('company_id', $company->id)->count());
    }

    public function test_missing_non_null_mapped_code_fails_instead_of_using_general_expense(): void
    {
        $company = $this->company('ZZ');
        (new GenericChartOfAccountsSeeder)->run($company->id, $company->tenant_id);
        Account::query()->where('company_id', $company->id)->where('code', '6130')->delete();

        try {
            app(ExpenseCategorySeeder::class)->seedForCompany($company);
            self::fail('A missing mapped code must fail loudly.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('6130', $exception->getMessage());
        }

        self::assertSame(0, ExpenseCategory::query()->where('company_id', $company->id)->count());
    }

    public function test_deliberate_null_mapping_uses_general_expense(): void
    {
        $company = $this->company('ZZ');
        (new GenericChartOfAccountsSeeder)->run($company->id, $company->tenant_id);

        app(ExpenseCategorySeeder::class)->seedForCompany($company);

        $category = ExpenseCategory::query()
            ->where('company_id', $company->id)
            ->where('name', 'Rent')
            ->firstOrFail();
        self::assertSame(
            '6280',
            Account::query()->whereKey($category->account_id)->value('code'),
        );
    }

    private function company(string $countryCode): Company
    {
        $tenant = Tenant::query()->create([
            'name' => 'M5 expense category',
            'slug' => 'm5-expense-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        return Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'M5 expense category',
            'country_code' => $countryCode,
            'currency' => 'EUR',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }
}
