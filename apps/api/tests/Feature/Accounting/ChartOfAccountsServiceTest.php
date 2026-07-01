<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountsService::class);

        // Create a tenant for testing
        $this->tenant = Tenant::create([
            'name' => 'Test Account',
            'slug' => 'test-account-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(string $countryCode = 'TN'): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company '.uniqid(),
            'country_code' => $countryCode,
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'en_US',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'UTC',
        ]);
    }

    public function test_seeds_chart_of_accounts_for_tunisia(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        $accounts = Account::where('company_id', $company->id)->get();

        // Tunisia COA has around 80+ accounts
        $this->assertGreaterThan(50, $accounts->count());
    }

    public function test_tunisia_chart_includes_sales_stamp_duty_account(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        $account = Account::findByPurpose($company->id, SystemAccountPurpose::SalesStampDutyPayable);

        $this->assertNotNull($account, 'TN chart must map a collected sales stamp-duty (timbre) account');
        $this->assertSame('4375', $account->code);
        $this->assertSame(AccountType::Liability, $account->type);
    }

    public function test_seeded_accounts_have_system_purposes(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        // Verify all required purposes are assigned
        foreach (SystemAccountPurpose::requiredPurposes() as $purpose) {
            $account = Account::findByPurpose($company->id, $purpose);
            $this->assertNotNull(
                $account,
                "Account with purpose {$purpose->value} should exist"
            );
        }
    }

    public function test_validate_company_accounts_passes_for_seeded_company(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['missing_purposes']);
    }

    public function test_validate_company_accounts_fails_for_empty_company(): void
    {
        $company = $this->createCompany('TN');

        // Don't seed

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['missing_purposes']);
    }

    public function test_seeds_generic_chart_for_country_without_dedicated_seeder(): void
    {
        $company = $this->createCompany('DE'); // No dedicated seeder — uses generic

        $this->service->seedForCompany($company);

        // Generic chart should have all required system accounts
        $result = $this->service->validateCompanyAccounts($company->id);
        $this->assertTrue($result['valid'], 'Generic chart should satisfy all required system purposes');
    }

    public function test_get_account_by_purpose_returns_correct_account(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        $receivable = $this->service->getAccountByPurpose(
            $company->id,
            SystemAccountPurpose::CustomerReceivable
        );

        $this->assertNotNull($receivable);
        $this->assertSame('411', $receivable->code); // Tunisia COA code for customers
        $this->assertSame(SystemAccountPurpose::CustomerReceivable, $receivable->system_purpose);
    }

    public function test_get_accounts_with_purposes_returns_all_accounts(): void
    {
        $company = $this->createCompany('TN');

        $this->service->seedForCompany($company);

        $accounts = $this->service->getAccountsWithPurposes($company->id);

        $this->assertNotEmpty($accounts);
        $this->assertArrayHasKey('id', $accounts[0]);
        $this->assertArrayHasKey('code', $accounts[0]);
        $this->assertArrayHasKey('name', $accounts[0]);
        $this->assertArrayHasKey('type', $accounts[0]);
        $this->assertArrayHasKey('system_purpose', $accounts[0]);
        $this->assertArrayHasKey('is_system', $accounts[0]);
    }

    public function test_assign_purpose_updates_account(): void
    {
        $company = $this->createCompany('TN');

        // Create an account without a purpose
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '99999',
            'name' => 'Test Account',
            'type' => 'asset',
            'is_active' => true,
            'is_system' => false,
        ]);

        $this->service->assignPurpose(
            $company->id,
            $account->id,
            SystemAccountPurpose::OpeningBalanceEquity
        );

        $account->refresh();

        $this->assertSame(
            SystemAccountPurpose::OpeningBalanceEquity,
            $account->system_purpose
        );
    }

    public function test_assign_purpose_fails_if_already_assigned(): void
    {
        $company = $this->createCompany('TN');

        // Seed COA (which assigns purposes)
        $this->service->seedForCompany($company);

        // Create a new account and try to assign an already-used purpose
        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '99999',
            'name' => 'Test Account',
            'type' => 'asset',
            'is_active' => true,
            'is_system' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Purpose 'customer_receivable' is already assigned");

        $this->service->assignPurpose(
            $company->id,
            $account->id,
            SystemAccountPurpose::CustomerReceivable
        );
    }

    public function test_remove_purpose_clears_account_purpose(): void
    {
        $company = $this->createCompany('TN');

        $account = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => '99999',
            'name' => 'Test Account',
            'type' => 'equity',
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => false,
        ]);

        $this->service->removePurpose($company->id, $account->id);

        $account->refresh();

        $this->assertNull($account->system_purpose);
    }

    public function test_get_supported_countries_returns_tunisia_and_france(): void
    {
        $countries = $this->service->getSupportedCountries();

        $this->assertContains('TN', $countries);
        $this->assertContains('FR', $countries);
    }

    public function test_get_available_purposes_returns_all_purposes(): void
    {
        $purposes = $this->service->getAvailablePurposes();

        $this->assertCount(count(SystemAccountPurpose::cases()), $purposes);

        // Check structure
        foreach ($purposes as $purpose) {
            $this->assertArrayHasKey('value', $purpose);
            $this->assertArrayHasKey('label', $purpose);
        }
    }
}
