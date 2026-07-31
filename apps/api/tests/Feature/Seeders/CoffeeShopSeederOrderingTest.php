<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CoffeeShopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T7 — Guard that CoffeeShopSeeder seeds reference data (countries) AFTER the
 * tenant is created and its database connection is initialized, not before.
 *
 * Under db-per-tenant, seeding `countries` before `tenancy()->initialize()` would
 * write the row into the central database instead of the tenant database, causing
 * CompanyTaxProvisioningService (T8) to fail with a missing country row when it
 * later queries the tenant connection.
 *
 * SQLITE LIMITATION: The test harness runs on a single SQLite connection shared
 * by all tables (central + tenant-scoped). The db-per-tenant ordering bug
 * (reference data landing in the central DB instead of the tenant DB) does NOT
 * reproduce under this harness because there is only one connection and no
 * physical database swap occurs. The real guard here is the CODE ORDER verified
 * by reading CoffeeShopSeeder::run(): tenant creation (step 1) must precede
 * CountriesSeeder (step 2). This test complements that static verification by
 * asserting the seeder completes successfully and the expected data is present.
 */
final class CoffeeShopSeederOrderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CoffeeShopSeeder must run to completion without throwing.
     * The countries table (TN row) must be populated in the test DB.
     * The coffee-shop Company must have been created with country_code TN.
     *
     * NOTE: default_tax_configuration_id is NOT asserted here — that FK is
     * populated by Task 8 (CompanyTaxProvisioningService), which is outside
     * this task's scope.
     */
    public function test_seeder_completes_and_reference_data_is_present(): void
    {
        // $this->seed() throws on failure, so reaching the assertions means the
        // seeder completed successfully.
        $this->seed(CoffeeShopSeeder::class);

        // The countries table must have been seeded (TN row present).
        $this->assertDatabaseHas('countries', ['code' => 'TN']);

        // The coffee-shop company must exist with the expected country_code.
        $company = Company::where('country_code', 'TN')->first();
        $this->assertNotNull($company, 'Coffee-shop Company (country_code=TN) was not created');
        $this->assertSame('TN', $company->country_code);

        // Tenant must be present.
        $this->assertDatabaseHas('tenants', ['slug' => 'cafe-tunis']);

        // First-tenant launch, Lane D1 task 1 — CountryPaymentSettingsSeeder
        // must have run (after CountriesSeeder) so a fresh coffee-shop tenant
        // has a settings row with rounding DISABLED, not silently absent.
        $this->assertDatabaseHas('country_payment_settings', [
            'country_code' => 'TN',
            'cash_rounding_enabled' => false,
        ]);

        // First-tenant launch, Lane D1 task 2 — CoffeeShopSeeder's seeded
        // terminal (seedTerminal(), Terminal::create() with no explicit
        // fiscal_schema_version) is the FOURTH creation site that relies on
        // the migration's column DEFAULT rather than an explicit value; pin
        // that it actually lands at 3.
        $terminal = Terminal::forCompany($company->id)->firstOrFail();
        $this->assertSame(3, $terminal->fiscal_schema_version, 'CoffeeShopSeeder terminal must default to fiscal schema 3.');
    }

    /**
     * The tenant row must survive once (exactly one cafe-tunis tenant exists).
     */
    public function test_tenant_row_is_unique_after_seeding(): void
    {
        $this->seed(CoffeeShopSeeder::class);

        $this->assertSame(
            1,
            Tenant::where('slug', 'cafe-tunis')->count(),
            'Exactly one cafe-tunis tenant must exist after seeding'
        );
    }
}
