<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Expense\Domain\ExpenseCategory;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\LegacyMockInterface;
use Tests\TestCase;

class TenantInitializationTest extends TestCase
{
    use RefreshDatabase;

    private TenantInitializationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);

        $this->service = app(TenantInitializationService::class);
    }

    public function test_initialization_sets_default_tax_rate_for_tunisian_company(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $company->refresh();
        $this->assertEquals('19.00', $company->default_tax_rate);
    }

    public function test_initialization_sets_default_tax_rate_for_french_company(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('FR', 'EUR');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $company->refresh();
        $this->assertEquals('20.00', $company->default_tax_rate);
    }

    public function test_initialization_sets_zero_tax_rate_for_unsupported_country(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('US', 'USD');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $company->refresh();
        $this->assertEquals('0.00', $company->default_tax_rate);
    }

    public function test_initialization_seeds_default_expense_categories(): void
    {
        // Register G-3: real tenants used to receive NONE — the expense-category
        // seeder was reachable only from the two parapharmacy demo seeders.
        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $categories = ExpenseCategory::query()->where('company_id', $company->id)->get();
        $this->assertGreaterThanOrEqual(6, $categories->count());

        foreach ($categories as $category) {
            $this->assertNotNull($category->account_id, "Category '{$category->name}' has no GL account");
        }

        $utilities = $categories->firstWhere('name', 'Eau & Électricité');
        $this->assertNotNull($utilities);
        $this->assertSame(
            '6061',
            Account::query()->whereKey($utilities->account_id)->value('code'),
        );
    }

    public function test_initialization_seeds_tunisia_tax_configurations(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        // Tunisia should have VAT rates seeded
        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'TVA_19',
            'percentage_rate' => '19.00',
            'is_default' => true,
        ]);

        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'TVA_13',
            'percentage_rate' => '13.00',
        ]);

        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'TVA_7',
            'percentage_rate' => '7.00',
        ]);

        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'TVA_EXEMPT',
            'percentage_rate' => '0.00',
        ]);

        // Stamp duties
        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'STAMP_TAX_INVOICE',
            'is_stamp_duty' => true,
        ]);

        $this->assertDatabaseHas('tax_configurations', [
            'country_code' => 'TN',
            'code' => 'STAMP_FISCAL_RECEIPT',
            'is_stamp_duty' => true,
        ]);
    }

    public function test_initialization_does_not_duplicate_tax_configurations_on_second_tunisian_company(): void
    {
        [$tenant1, $company1, $user1] = $this->createTenantCompanyUser('TN', 'TND');
        $this->service->initializeForNewRegistration($tenant1, $company1, $user1);

        $countBefore = TaxConfiguration::where('country_code', 'TN')->count();

        [$tenant2, $company2, $user2] = $this->createTenantCompanyUser('TN', 'TND', 'second');
        $this->service->initializeForNewRegistration($tenant2, $company2, $user2);

        $countAfter = TaxConfiguration::where('country_code', 'TN')->count();

        $this->assertEquals($countBefore, $countAfter, 'Tax configurations should not be duplicated');
    }

    public function test_registration_endpoint_sets_tax_rate_for_tunisian_company(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Tunisian User',
            'email' => 'tn@example.com',
            'password' => 'MyStr0ng!Pass',
            'password_confirmation' => 'MyStr0ng!Pass',
            'company_name' => 'Tunisian Company',
            'country_code' => 'TN',
            'vertical' => 'retail',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('companies', [
            'name' => 'Tunisian Company',
            'country_code' => 'TN',
            'default_tax_rate' => '19.00',
        ]);
    }

    /**
     * Register G-11. When the central `plans` table has no `trial` row the
     * tenant is initialized WITHOUT any subscription and nothing anywhere says
     * so — the tenant simply has no billing state, discovered much later. The
     * absence must stay non-fatal (provisioning must not fail for it) but must
     * be loud in the log, under a stable grep token, naming the remedy.
     */
    public function test_absent_trial_plan_is_logged_with_a_stable_grep_token(): void
    {
        $this->assertDatabaseMissing('plans', ['code' => 'trial']);

        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $logSpy = Log::spy();

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('warning', [
            // The grep token is the contract: operators search logs for it, so
            // it must survive any rewording of the surrounding sentence.
            Mockery::on(static fn (string $message): bool => str_contains($message, 'TENANT-INIT TRIAL-PLAN-ABSENT')
                && str_contains($message, 'PlansSeeder')),
            Mockery::on(fn (array $context): bool => ($context['tenant_id'] ?? null) === $tenant->id),
        ]);

        // Non-fatal: initialization completed, it just produced no subscription.
        $this->assertDatabaseMissing('tenant_subscriptions', ['tenant_id' => $tenant->id]);
        $this->assertTrue($user->fresh()?->hasRole('admin'));
    }

    /**
     * Register G-11: the warning is a real signal, not noise — it must be
     * silent on the healthy path.
     */
    public function test_no_trial_plan_warning_when_plans_are_seeded(): void
    {
        $this->seed(PlansSeeder::class);

        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $logSpy = Log::spy();

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldNotHaveReceived('warning', [
            Mockery::on(static fn (string $message): bool => str_contains($message, 'TENANT-INIT TRIAL-PLAN-ABSENT')),
            Mockery::any(),
        ]);

        $this->assertDatabaseHas('tenant_subscriptions', ['tenant_id' => $tenant->id]);
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function createTenantCompanyUser(string $countryCode, string $currency, string $suffix = ''): array
    {
        $tenant = Tenant::create([
            'name' => "Test Tenant {$countryCode}{$suffix}",
            'slug' => 'test-tenant-'.strtolower($countryCode).$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => $currency,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test Company {$countryCode}{$suffix}",
            'country_code' => strtoupper($countryCode),
            'currency' => $currency,
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => "Test User {$countryCode}{$suffix}",
            'email' => strtolower($countryCode)."{$suffix}@test.com",
            'password' => 'password',
            'status' => 'active',
        ]);

        return [$tenant, $company, $user];
    }
}
