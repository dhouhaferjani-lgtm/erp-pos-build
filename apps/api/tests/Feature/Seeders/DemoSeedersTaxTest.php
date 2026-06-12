<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Database\Seeders\CoffeeShopSeeder;
use Database\Seeders\ParapharmacyMultiBranchSeeder;
use Database\Seeders\ParapharmacySeeder;
use Database\Seeders\TunisianParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T8 — CompanyTaxProvisioningService wired into all demo/vertical seeders.
 *
 * Each test seeds one vertical seeder in isolation and asserts that the company
 * it creates ends up with:
 *   - a non-null `default_tax_configuration_id` FK, and
 *   - the expected count of TaxConfiguration rows for that country.
 *
 * These tests are intentionally scoped to the most critical seeders (those that
 * create real FR/TN companies with full financial foundations). DemoTenantSeeder
 * is NOT tested end-to-end here because it is enormous and would exhaust the
 * test harness; its wiring is verified by code reading.
 */
final class DemoSeedersTaxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ParapharmacySeeder creates a French (FR) company and must provision
     * 5 TVA configuration rows (TVA 20%, 10%, 5.5%, 2.1%, 0%) + set the
     * company default FK.
     */
    public function test_parapharmacy_seeder_provisions_fr_tax(): void
    {
        $this->artisan('db:seed', ['--class' => ParapharmacySeeder::class, '--force' => true])
            ->assertExitCode(0);

        $company = Company::where('country_code', 'FR')->latest('id')->first();
        $this->assertNotNull($company, 'ParapharmacySeeder must create a FR company');
        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'company.default_tax_configuration_id must be set after provisioning'
        );
        $this->assertSame(
            5,
            TaxConfiguration::where('country_code', 'FR')->count(),
            'FranceTaxConfigurationSeeder must create exactly 5 TVA bands'
        );
    }

    /**
     * CoffeeShopSeeder creates a Tunisian (TN) company and must provision
     * at least 4 TaxConfiguration rows for TN (standard VAT, reduced, zero, stamp)
     * + set the company default FK.
     */
    public function test_coffeeshop_seeder_provisions_tn_tax(): void
    {
        $this->artisan('db:seed', ['--class' => CoffeeShopSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $company = Company::where('country_code', 'TN')->latest('id')->first();
        $this->assertNotNull($company, 'CoffeeShopSeeder must create a TN company');
        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'company.default_tax_configuration_id must be set after provisioning'
        );
        $this->assertGreaterThanOrEqual(
            4,
            TaxConfiguration::where('country_code', 'TN')->count(),
            'TunisiaTaxConfigurationSeeder must create at least 4 TN tax configurations'
        );
    }

    /**
     * ParapharmacyMultiBranchSeeder creates a French multi-branch company and
     * must provision FR tax configurations + set the company default FK.
     */
    public function test_parapharmacy_multi_branch_seeder_provisions_fr_tax(): void
    {
        $this->artisan('db:seed', ['--class' => ParapharmacyMultiBranchSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $company = Company::where('country_code', 'FR')->latest('id')->first();
        $this->assertNotNull($company, 'ParapharmacyMultiBranchSeeder must create a FR company');
        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'company.default_tax_configuration_id must be set after provisioning'
        );
        $this->assertSame(
            5,
            TaxConfiguration::where('country_code', 'FR')->count(),
            'FranceTaxConfigurationSeeder must create exactly 5 TVA bands'
        );
    }

    /**
     * TunisianParapharmacySeeder attaches to an existing tenant's TN company and
     * must provision TN tax configurations + set company.default_tax_configuration_id.
     *
     * This seeder requires an existing active tenant (created by DatabaseSeeder
     * which also seeds countries), so we run DatabaseSeeder first to establish
     * the base environment.
     */
    public function test_tunisian_parapharmacy_seeder_provisions_tn_tax(): void
    {
        // TunisianParapharmacySeeder attaches to the first active tenant, which
        // DatabaseSeeder creates (with countries already seeded).
        $this->artisan('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->artisan('db:seed', ['--class' => TunisianParapharmacySeeder::class, '--force' => true])
            ->assertExitCode(0);

        // TunisianParapharmacySeeder creates a TN company (tax_id TN1234567ABC)
        $company = Company::where('tax_id', 'TN1234567ABC')->first();
        $this->assertNotNull($company, 'TunisianParapharmacySeeder must create company with tax_id TN1234567ABC');
        $this->assertNotNull(
            $company->default_tax_configuration_id,
            'company.default_tax_configuration_id must be set after provisionForCompany()'
        );
        $this->assertGreaterThanOrEqual(
            4,
            TaxConfiguration::where('country_code', 'TN')->count(),
            'TN tax configurations must be provisioned'
        );
    }
}
