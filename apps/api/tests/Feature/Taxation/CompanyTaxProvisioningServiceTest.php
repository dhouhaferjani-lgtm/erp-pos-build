<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CompanyTaxProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_tn_configs_and_sets_company_default(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('TN');

        $this->service()->provisionForCompany($company, failLoudOnMissingCountry: true);

        $this->assertGreaterThanOrEqual(4, TaxConfiguration::where('country_code', 'TN')->count());
        $company->refresh();
        $default = TaxConfiguration::where('country_code', 'TN')->where('is_default', true)->first();
        if ($default === null) {
            $this->fail('TN provisioning must create a default tax configuration.');
        }
        $this->assertNotNull($company->default_tax_configuration_id);
        $this->assertSame($default->id, $company->default_tax_configuration_id);
        // Company.default_tax_rate is decimal(5,2); SQLite returns the numeric form ('19') while PG
        // returns '19.00'. Use assertEquals (loose) to be driver-agnostic — same convention as
        // TenantInitializationTest which passes on both drivers.
        $this->assertEquals('19.00', $company->default_tax_rate);
    }

    public function test_is_idempotent(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('FR');
        $service = $this->service();
        $service->provisionForCompany($company);
        $service->provisionForCompany($company);
        $this->assertSame(5, TaxConfiguration::where('country_code', 'FR')->count());
    }

    public function test_fails_loud_when_countries_missing(): void
    {
        $company = $this->makeCompany('TN'); // countries NOT seeded
        $this->expectException(RuntimeException::class);
        $this->service()->provisionForCompany($company, failLoudOnMissingCountry: true);
    }

    public function test_skips_silently_for_unsupported_country(): void
    {
        (new CountriesSeeder)->run();
        $company = $this->makeCompany('US');
        $this->service()->provisionForCompany($company, failLoudOnMissingCountry: true);
        $company->refresh();
        $this->assertNull($company->default_tax_configuration_id);
    }

    private function makeCompany(string $countryCode): Company
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant '.$countryCode,
            'slug' => 'test-tax-provisioning-'.strtolower($countryCode).'-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => match (strtoupper($countryCode)) {
                'TN' => 'TND',
                'FR' => 'EUR',
                default => 'USD',
            },
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company '.$countryCode,
            'country_code' => strtoupper($countryCode),
            'currency' => match (strtoupper($countryCode)) {
                'TN' => 'TND',
                'FR' => 'EUR',
                default => 'USD',
            },
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }

    private function service(): CompanyTaxProvisioningService
    {
        return $this->app->make(CompanyTaxProvisioningService::class);
    }
}
