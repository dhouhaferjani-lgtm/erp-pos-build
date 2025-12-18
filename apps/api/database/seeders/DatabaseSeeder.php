<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with test data.
     */
    public function run(): void
    {
        $this->command->info('Seeding countries...');
        $this->call(CountriesSeeder::class);

        $this->command->info('Seeding country tax rates...');
        $this->call(CountryTaxRatesSeeder::class);

        $this->command->info('Seeding subscription plans...');
        $this->call(PlansSeeder::class);

        $this->command->info('Creating super admin...');
        $this->call(SuperAdminSeeder::class);

        $this->command->info('Creating roles and permissions...');
        $this->call(RolesAndPermissionsSeeder::class);

        $this->command->info('Creating demo tenant...');
        $tenant = $this->createTenant();

        $this->command->info('Creating demo company...');
        $company = $this->createCompany($tenant);

        $this->command->info('Creating test users...');
        $this->createUsers($tenant, $company);

        $this->command->info('Creating payment methods...');
        $this->call(PaymentMethodSeeder::class);

        $this->command->info('Creating payment repositories...');
        $this->call(PaymentRepositorySeeder::class);

        $this->command->info('Creating test partners (customers and suppliers)...');
        $this->createPartners($tenant, $company);

        $this->command->info('Creating products...');
        $this->createProducts($company);

        $this->command->info('Creating vehicles...');
        $this->createVehicles($company);

        $this->command->info('Creating Smart Payment test data...');
        $this->call(SmartPaymentTestDataSeeder::class);

        $this->command->info('Database seeding completed!');
    }

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Demo Garage',
            'slug' => 'demo-garage',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'tax_id' => 'FR12345678901',
            'country_code' => 'FR',
            'currency_code' => 'EUR',
            'settings' => [
                'timezone' => 'Europe/Paris',
                'locale' => 'fr',
                'date_format' => 'd/m/Y',
                'fiscal_year_start' => '01-01',
            ],
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);
    }

    private function createCompany(Tenant $tenant): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Garage',
            'legal_name' => 'Demo Garage SARL',
            'country_code' => 'FR',
            'tax_id' => 'FR12345678901',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }

    private function createUsers(Tenant $tenant, Company $company): void
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

        // Create company membership for test user (manager role)
        UserCompanyMembership::create([
            'user_id' => $testUser->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Assign manager role with tenant scope
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'sanctum')->first();
        if ($managerRole) {
            $testUser->assignRole($managerRole);
            $this->command->info('Assigned manager role to test@example.com');
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

        // Create company membership for admin user (owner role)
        UserCompanyMembership::create([
            'user_id' => $adminUser->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Assign admin role with tenant scope
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $adminUser->assignRole($adminRole);
            $this->command->info('Assigned admin role to admin@example.com');
        }
    }

    private function createPartners(Tenant $tenant, Company $company): void
    {
        // Create 50 customers using factory
        Partner::factory()
            ->count(50)
            ->customer()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 30 suppliers using factory
        Partner::factory()
            ->count(30)
            ->supplier()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 10 partners that are both customer and supplier
        Partner::factory()
            ->count(10)
            ->both()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        // Create 5 inactive partners
        Partner::factory()
            ->count(5)
            ->inactive()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        $this->command->info('Created 95 partners (50 customers, 30 suppliers, 10 both, 5 inactive)');
    }

    private function createProducts(Company $company): void
    {
        $this->command->info('Creating 1000 products (this may take a moment)...');

        // Create 800 physical products (goods) in batches
        $goodsCount = 800;
        $batchSize = 100;
        for ($i = 0; $i < $goodsCount; $i += $batchSize) {
            \App\Modules\Product\Domain\Product::factory()
                ->count(min($batchSize, $goodsCount - $i))
                ->goods()
                ->create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                ]);
        }

        // Create 150 services
        \App\Modules\Product\Domain\Product::factory()
            ->count(150)
            ->service()
            ->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
            ]);

        // Create 50 inactive products
        \App\Modules\Product\Domain\Product::factory()
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

            \App\Modules\Vehicle\Domain\Vehicle::factory()
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
