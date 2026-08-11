<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with test data.
     */
    public function run(CompanyTaxProvisioningService $companyTaxProvisioning): void
    {
        // CENTRAL reference data (plans live in the central database; the super
        // admin is a platform-level account in central). Seed BEFORE creating
        // the tenant so the plan exists when the tenant database is provisioned.
        $this->command->info('Seeding subscription plans...');
        $this->call(PlansSeeder::class);

        $this->command->info('Creating super admin...');
        $this->call(SuperAdminSeeder::class);

        // ============================================
        // MULTI-COUNTRY TENANT (France + Tunisia)
        // ============================================
        // In db-per-tenant mode createTenant() provisions + migrates the physical
        // tenant database and swaps the default connection into it, so every
        // tenant-scoped seed below lands in the tenant database, not central.
        $this->command->info('Creating multi-country demo tenant...');
        $tenant = $this->createTenant('FR', 'Demo Multi-Country Garage', 'demo-garage', 'EUR');

        // TENANT-scoped reference data (these tables live in the tenant database
        // under db-per-tenant — countries, country_tax_rates, the Spatie
        // roles/permissions, and the parapharmacy reference catalogs).
        $this->command->info('Seeding countries...');
        $this->call(CountriesSeeder::class);

        // MUST run after CountriesSeeder — country_payment_settings.country_code
        // FKs into countries (spec §4.2 greenfield self-healing). Without this a
        // fresh demo tenant has no settings row, no PosPaymentPolicyResolver
        // fallback source, and pos:configure-cash-rounding cannot create one for
        // the tenant.
        $this->command->info('Seeding country payment settings...');
        $this->call(CountryPaymentSettingsSeeder::class);

        $this->command->info('Seeding country tax rates...');
        $this->call(CountryTaxRatesSeeder::class);

        $this->command->info('Creating roles and permissions...');
        $this->call(RolesAndPermissionsSeeder::class);

        $this->command->info('Seeding parapharmacy reference data...');
        $this->call(IngredientsSeeder::class);
        $this->call(CertificationsSeeder::class);
        $this->call(HealthClaimsSeeder::class);
        $this->call(KeyComponentsSeeder::class);

        // ============================================
        // FRENCH COMPANY
        // ============================================
        $this->command->info('Creating French company...');
        $frCompany = $this->createCompany($tenant, 'FR', 'Demo Garage France', 'EUR');

        $this->command->info('Creating French chart of accounts...');
        $franceSeeder = new FranceChartOfAccountsSeeder;
        $franceSeeder->setCommand($this->command);
        $franceSeeder->run($frCompany->id, $tenant->id);

        $this->command->info('Creating payment methods for French company...');
        $this->call(PaymentMethodSeeder::class, false, ['company' => $frCompany]);

        $this->command->info('Creating payment repositories for French company...');
        $this->call(PaymentRepositorySeeder::class, false, ['company' => $frCompany]);

        $this->command->info('Provisioning tax configurations for French company...');
        $companyTaxProvisioning->provisionForCompany($frCompany, failLoudOnMissingCountry: true);

        $this->command->info('Creating partners for French company...');
        $this->createPartners($tenant, $frCompany);

        $this->command->info('Creating products for French company...');
        $this->createProducts($frCompany);

        $this->command->info('Creating vehicles for French company...');
        $this->createVehicles($frCompany);

        $this->command->info('Creating stock levels for French company...');
        $this->call(StockLevelSeeder::class, false, ['company' => $frCompany]);

        // ============================================
        // TUNISIAN COMPANY (same tenant)
        // ============================================
        $this->command->info('Creating Tunisian company...');
        $tnCompany = $this->createCompany($tenant, 'TN', 'Demo Garage Tunisia', 'TND');

        $this->command->info('Creating Tunisian chart of accounts...');
        $tunisiaSeeder = new TunisiaChartOfAccountsSeeder;
        $tunisiaSeeder->setCommand($this->command);
        $tunisiaSeeder->run($tnCompany->id, $tenant->id);

        $this->command->info('Creating payment methods for Tunisian company...');
        $this->call(PaymentMethodSeeder::class, false, ['company' => $tnCompany]);

        $this->command->info('Creating payment repositories for Tunisian company...');
        $this->call(PaymentRepositorySeeder::class, false, ['company' => $tnCompany]);

        $this->command->info('Provisioning tax configurations for Tunisian company...');
        $companyTaxProvisioning->provisionForCompany($tnCompany, failLoudOnMissingCountry: true);

        $this->command->info('Creating partners for Tunisian company...');
        $this->createPartners($tenant, $tnCompany);

        $this->command->info('Creating products for Tunisian company...');
        $this->createProducts($tnCompany);

        $this->command->info('Creating vehicles for Tunisian company...');
        $this->createVehicles($tnCompany);

        $this->command->info('Creating stock levels for Tunisian company...');
        $this->call(StockLevelSeeder::class, false, ['company' => $tnCompany]);

        // ============================================
        // USERS (add to BOTH companies)
        // ============================================
        $this->command->info('Creating test users with access to both companies...');
        $this->createUsers($tenant, $frCompany, $tnCompany);

        // ============================================
        // SHARED DATA
        // ============================================
        $this->command->info('Creating Smart Payment test data...');
        $this->call(SmartPaymentTestDataSeeder::class);

        $this->command->info('Applying batch tracking defaults...');
        $this->call(BatchTrackingDefaultsSeeder::class);

        $this->command->info('Database seeding completed with 2 companies (France + Tunisia) in one tenant!');

        // Revert the default connection back to central (no-op in single-DB).
        $this->endTenancy();
    }

    private function createTenant(string $countryCode, string $name, string $slug, string $currency): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => 'mechanic',
            'tax_id' => $countryCode.'12345678901',
            'country_code' => $countryCode,
            'currency_code' => $currency,
            'settings' => [
                'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'Europe/Paris',
                'locale' => 'fr',
                'date_format' => 'd/m/Y',
                'fiscal_year_start' => '01-01',
            ],
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        // db-per-tenant: provision + migrate the physical tenant database and
        // swap the default connection into it so every tenant-scoped seed lands
        // in the tenant database. No-op in single-DB mode. Mirrors
        // ParapharmacySeeder / TenantProvisioningService.
        $this->provisionTenantDatabase($tenant);

        return $tenant;
    }

    /**
     * Whether database-per-tenant mode is active.
     */
    private function databasePerTenantEnabled(): bool
    {
        return (bool) config('tenancy_resolver.db_per_tenant', false);
    }

    /**
     * Provision + migrate the per-tenant database and enter tenant context.
     * No-op in single-DB mode.
     *
     * NOTE: duplicated from ParapharmacySeeder / CoffeeShopSeeder — a future
     * refactor should hoist this provisioning trio into a shared seeder trait.
     */
    private function provisionTenantDatabase(Tenant $tenant): void
    {
        if (! $this->databasePerTenantEnabled()) {
            return;
        }

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        tenancy()->initialize($tenant);

        $this->command->info("Provisioned tenant database: {$tenant->database()->getName()}");
    }

    /**
     * Revert the default connection to central. No-op in single-DB mode (and
     * when tenancy was never initialized).
     */
    private function endTenancy(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    private function createCompany(Tenant $tenant, string $countryCode, string $name, string $currency): Company
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'legal_name' => $name.' SARL',
            'country_code' => $countryCode,
            'tax_id' => $countryCode.'12345678901',
            'currency' => $currency,
            'locale' => 'fr',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,

            // Address fields based on country
            'address_street' => $countryCode === 'FR' ? '123 Rue de la Paix' : '456 Avenue Habib Bourguiba',
            'address_city' => $countryCode === 'FR' ? 'Paris' : 'Tunis',
            'address_postal_code' => $countryCode === 'FR' ? '75002' : '1000',
            'address_state' => null,
            'phone' => $countryCode === 'FR' ? '+33 1 42 86 82 00' : '+216 71 123 456',
            'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);

        // Create default location with company address
        Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'name' => 'Main Location',
            'code' => 'MAIN',
            'type' => 'shop', // Primary location for service businesses
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
            'address_street' => $company->address_street,
            'address_city' => $company->address_city,
            'address_postal_code' => $company->address_postal_code,
            'address_country' => $company->country_code,
            'phone' => $company->phone,
            'email' => $company->email,
        ]);

        return $company;
    }

    private function createUsers(Tenant $tenant, Company $frenchCompany, Company $tunisianCompany): void
    {
        // Set the team (tenant) context for Spatie permissions
        setPermissionsTeamId($tenant->id);

        // Test User - assign manager role (full operational access)
        $testUser = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
        ]);

        // Create company memberships for test user in BOTH companies
        UserCompanyMembership::create([
            'user_id' => $testUser->id,
            'company_id' => $frenchCompany->id,
            'role' => MembershipRole::Manager,
            'is_primary' => true, // French company is primary
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        UserCompanyMembership::create([
            'user_id' => $testUser->id,
            'company_id' => $tunisianCompany->id,
            'role' => MembershipRole::Manager,
            'is_primary' => false, // Tunisia is secondary
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Assign manager role with tenant scope
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'sanctum')->first();
        if ($managerRole) {
            $testUser->assignRole($managerRole);
            $this->command->info('Assigned manager role to test@example.com (access to both companies)');
        }

        // Admin User - assign admin role (full access)
        $adminUser = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
        ]);

        // Create company memberships for admin user in BOTH companies
        UserCompanyMembership::create([
            'user_id' => $adminUser->id,
            'company_id' => $frenchCompany->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true, // French company is primary
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        UserCompanyMembership::create([
            'user_id' => $adminUser->id,
            'company_id' => $tunisianCompany->id,
            'role' => MembershipRole::Owner,
            'is_primary' => false, // Tunisia is secondary
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Assign admin role with tenant scope
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $adminUser->assignRole($adminRole);
            $this->command->info('Assigned admin role to admin@example.com (access to both companies)');
        }
    }

    private function createPartners(Tenant $tenant, Company $company): void
    {
        // Determine country state based on company
        $countryState = $company->country_code === 'FR' ? 'france' : 'tunisia';

        // Create 50 customers using factory
        Partner::factory()
            ->count(50)
            ->customer()
            ->$countryState()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 30 suppliers using factory
        Partner::factory()
            ->count(30)
            ->supplier()
            ->$countryState()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 10 partners that are both customer and supplier
        Partner::factory()
            ->count(10)
            ->both()
            ->$countryState()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 5 inactive partners
        Partner::factory()
            ->count(5)
            ->inactive()
            ->$countryState()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        $this->command->info("Created 95 partners for {$company->name} (50 customers, 30 suppliers, 10 both, 5 inactive)");
    }

    private function createProducts(Company $company): void
    {
        $this->command->info('Creating 1000 products (this may take a moment)...');

        // Create 800 physical products (goods) in batches
        $goodsCount = 800;
        $batchSize = 100;
        for ($i = 0; $i < $goodsCount; $i += $batchSize) {
            Product::factory()
                ->count(min($batchSize, $goodsCount - $i))
                ->goods()
                ->create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                ]);
        }

        // Create 150 services
        Product::factory()
            ->count(150)
            ->service()
            ->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
            ]);

        // Create 50 inactive products
        Product::factory()
            ->count(50)
            ->inactive()
            ->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
            ]);

        $this->command->info('Created 1000 products (800 goods, 150 services, 50 inactive)');
    }

    private function createVehicles(Company $company): void
    {
        // Get all customers (partners with type customer or both)
        $customers = Partner::where('company_id', $company->id)
            ->whereIn('type', ['customer', 'both'])
            ->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers found to assign vehicles');

            return;
        }

        $vehicleCount = 0;

        // Assign 1-3 vehicles to 8 random customers
        $selectedCustomers = $customers->random(min(8, $customers->count()));

        foreach ($selectedCustomers as $customer) {
            $count = rand(1, 3);

            Vehicle::factory()
                ->count($count)
                ->create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'partner_id' => $customer->id,
                ]);

            $vehicleCount += $count;
        }

        $this->command->info("Created {$vehicleCount} vehicles for {$selectedCustomers->count()} customers");
    }
}
