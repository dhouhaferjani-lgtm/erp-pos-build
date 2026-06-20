<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that TenantInitializationService delegates tax provisioning to
 * CompanyTaxProvisioningService and sets company.default_tax_configuration_id.
 *
 * This test guards Task 5: the FK column must be populated after registration
 * (previously it was always null, causing the onboarding checklist to show
 * tax as unconfigured even for TN/FR companies).
 */
final class TenantInitializationTaxTest extends TestCase
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

    /**
     * After initializeForNewRegistration for a TN company:
     * - company.default_tax_configuration_id is set to the TN is_default config,
     * - company.default_tax_rate ≈ '19.00' (driver-agnostic comparison via bccomp),
     * - at least 4 TN TaxConfiguration rows exist.
     */
    public function test_tn_registration_sets_default_tax_configuration_id(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('TN', 'TND');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $fresh = Company::findOrFail($company->id);
        $this->assertNotNull($fresh->default_tax_configuration_id);

        $default = TaxConfiguration::where('country_code', 'TN')
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($default, 'TN is_default TaxConfiguration must exist after init');
        $this->assertSame($default->id, $fresh->default_tax_configuration_id);

        // bccomp is used for driver-agnostic decimal comparison:
        // SQLite returns '19', PG returns '19.00' — both compare equal at scale 2.
        // is_numeric in an if/fail pattern narrows the type to numeric-string.
        $rawRate = (string) $fresh->default_tax_rate;
        if (! is_numeric($rawRate)) {
            $this->fail("default_tax_rate must be numeric, got '{$rawRate}'");
        }
        $this->assertSame(0, bccomp($rawRate, '19.00', 2), "Expected ≈ 19.00, got '{$rawRate}'");

        $this->assertGreaterThanOrEqual(
            4,
            TaxConfiguration::where('country_code', 'TN')->count(),
            'At least 4 TN TaxConfiguration rows should be seeded'
        );
    }

    /**
     * After initializeForNewRegistration for a FR company:
     * - company.default_tax_configuration_id is set (France now gets configs too),
     * - company.default_tax_rate ≈ '20.00'.
     */
    public function test_fr_registration_sets_default_tax_configuration_id(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('FR', 'EUR');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $fresh = Company::findOrFail($company->id);
        $this->assertNotNull($fresh->default_tax_configuration_id);

        $rawRate = (string) $fresh->default_tax_rate;
        if (! is_numeric($rawRate)) {
            $this->fail("default_tax_rate must be numeric, got '{$rawRate}'");
        }
        $this->assertSame(0, bccomp($rawRate, '20.00', 2), "Expected ≈ 20.00, got '{$rawRate}'");
    }

    /**
     * A country with seeded standard VAT data but no dedicated tax-config seeder
     * (DE) must still receive a DATA-DRIVEN default_tax_rate from country_tax_rates
     * (19%), not a hardcoded fallback. Guards the de-hardcoding of setDefaultTaxRate.
     */
    public function test_country_with_rate_data_but_no_seeder_uses_data_driven_default_rate(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('DE', 'EUR');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $fresh = Company::findOrFail($company->id);

        // No tax-config seeder is registered for DE → the FK stays null...
        $this->assertNull($fresh->default_tax_configuration_id);

        // ...but the default rate must be the German standard VAT (19%) read from
        // country_tax_rates, not a hardcoded 0.00.
        $rawRate = (string) $fresh->default_tax_rate;
        if (! is_numeric($rawRate)) {
            $this->fail("default_tax_rate must be numeric, got '{$rawRate}'");
        }
        $this->assertSame(0, bccomp($rawRate, '19.00', 2), "Expected ≈ 19.00 from data, got '{$rawRate}'");
    }

    /**
     * For an unsupported country (US) the FK should remain null and the rate 0.00.
     */
    public function test_unsupported_country_leaves_tax_configuration_id_null(): void
    {
        [$tenant, $company, $user] = $this->createTenantCompanyUser('US', 'USD');

        $this->service->initializeForNewRegistration($tenant, $company, $user);

        $fresh = Company::findOrFail($company->id);
        $this->assertNull($fresh->default_tax_configuration_id);

        $rawRate = (string) $fresh->default_tax_rate;
        if (! is_numeric($rawRate)) {
            $this->fail("default_tax_rate must be numeric, got '{$rawRate}'");
        }
        $this->assertSame(0, bccomp($rawRate, '0.00', 2), "Expected ≈ 0.00, got '{$rawRate}'");
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function createTenantCompanyUser(string $countryCode, string $currency, string $suffix = ''): array
    {
        $tenant = Tenant::create([
            'name' => "Tax Test Tenant {$countryCode}{$suffix}",
            'slug' => 'tax-test-tenant-'.strtolower($countryCode).$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => strtoupper($countryCode),
            'currency_code' => $currency,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Tax Test Company {$countryCode}{$suffix}",
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
            'name' => "Tax Test User {$countryCode}{$suffix}",
            'email' => 'tax-'.strtolower($countryCode)."{$suffix}@test.com",
            'password' => 'password',
            'status' => 'active',
        ]);

        return [$tenant, $company, $user];
    }
}
