<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production Seeder - Essential lookup data only
 *
 * This seeder is safe to run on production databases.
 * It only creates lookup/reference data that is required
 * for the application to function, without creating any
 * tenant, user, or transactional data.
 *
 * Usage: php artisan db:seed --class=ProductionSeeder
 */
class ProductionSeeder extends Seeder
{
    /**
     * Seed the application's database with production-required lookup data.
     */
    public function run(): void
    {
        $this->command->info('========================================');
        $this->command->info('AutoERP Production Seeder');
        $this->command->info('========================================');
        $this->command->newLine();

        // 1. Countries (lookup table)
        $this->command->info('[1/5] Seeding countries...');
        $this->call(CountriesSeeder::class);
        $this->command->info('     Countries seeded successfully.');

        // 1b. Country Payment Settings (tolerance ceilings + cash-rounding
        //     denomination). MUST run after CountriesSeeder — country_code FK.
        $this->command->info('[1b/6] Seeding country payment settings...');
        $this->call(CountryPaymentSettingsSeeder::class);
        $this->call(CountryDocumentSettingsSeeder::class);
        $this->command->info('     Country payment settings seeded successfully.');

        // 1c. Country Inventory Settings (valuation mode). Same FK ordering
        //     constraint as 1b (DPA Wave 3 T8).
        $this->command->info('[1c/6] Seeding country inventory settings...');
        $this->call(CountryInventorySettingsSeeder::class);
        $this->command->info('     Country inventory settings seeded successfully.');

        // 2. Country Tax Rates (lookup table)
        $this->command->info('[2/6] Seeding country tax rates...');
        $this->call(CountryTaxRatesSeeder::class);
        $this->command->info('     Tax rates seeded successfully.');

        // 3. Country Tax Configurations (VAT rates; no company FK to set here —
        //    ProductionSeeder creates no company, so we call the seeders directly
        //    rather than going through CompanyTaxProvisioningService).
        $this->command->info('[3/6] Seeding country tax configurations...');
        (new TunisiaTaxConfigurationSeeder)->run();
        $this->command->info('     Tunisia tax configurations seeded successfully.');
        (new FranceTaxConfigurationSeeder)->run();
        $this->command->info('     France tax configurations seeded successfully.');

        // 4. Subscription Plans (required for tenant creation)
        $this->command->info('[4/6] Seeding subscription plans...');
        $this->call(PlansSeeder::class);
        $this->command->info('     Plans seeded successfully.');

        // 5. Roles and Permissions (required for authorization)
        $this->command->info('[5/6] Seeding roles and permissions...');
        $this->call(RolesAndPermissionsSeeder::class);
        $this->command->info('     Roles and permissions seeded successfully.');

        // 6. Individual Permissions (additional granular permissions)
        $this->command->info('[6/6] Seeding individual permissions...');
        $this->call(PermissionSeeder::class);
        $this->command->info('     Individual permissions seeded successfully.');

        // Note: Payment methods are seeded per-company during tenant initialization
        // See TenantInitializationService for per-tenant seeding

        $this->command->newLine();
        $this->command->info('========================================');
        $this->command->info('Production seeding completed!');
        $this->command->info('========================================');
        $this->command->newLine();
        $this->command->info('The database is now ready for user registration.');
        $this->command->info('Users can sign up and create their own tenants.');
    }
}
