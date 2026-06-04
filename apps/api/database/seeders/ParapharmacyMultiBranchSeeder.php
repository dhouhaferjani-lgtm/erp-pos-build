<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * ParapharmacyMultiBranchSeeder - Multi-branch parapharmacy demo fixture.
 *
 * Extends {@see ParapharmacySeeder} to reuse the entire product / chart-of-
 * accounts / partner / reference-data setup, but provisions a MULTI-LOCATION
 * topology within the SAME tenant + company so the demo can show:
 *   - warehouse → shop stock transfers, and
 *   - per-location user isolation (a Paris cashier cannot see Lyon stock).
 *
 * Topology (one tenant "PharmaBio France", one company "PharmaBio France SAS"):
 *   1. Central Warehouse (type Warehouse, NOT POS-enabled) — holds the bulk
 *      of inventory, the source for transfers to the shops.
 *   2. PharmaBio Paris (type Shop, POS-enabled) — small front-of-house stock.
 *   3. PharmaBio Lyon  (type Shop, POS-enabled) — small front-of-house stock.
 *
 * Terminals: one physical POS terminal per shop (POS01 @ Paris, POS01 @ Lyon —
 * the code is unique per (tenant, company, location)).
 *
 * Users (password `password` for all):
 *   - owner@pharmabio.fr   (PIN 1234) — all locations (allowed_location_ids = NULL)
 *   - manager@pharmabio.fr (PIN 5678) — all locations (can transfer across branches)
 *   - cashier@pharmabio.fr (PIN 0000) — all locations (legacy base cashier)
 *   - paris.cashier@pharmabio.fr (PIN 1111) — Paris shop ONLY
 *   - lyon.cashier@pharmabio.fr  (PIN 2222) — Lyon shop ONLY
 *
 * Per-location scoping is enforced via
 * {@see UserCompanyMembership::$allowed_location_ids}
 * (NULL = all locations; otherwise a whitelist of location UUIDs) and read back
 * through {@see UserCompanyMembership::canAccessLocation()}.
 *
 * Usage: php artisan db:seed --class=ParapharmacyMultiBranchSeeder
 *        PARAPHARMACY_SEEDER_SCALE=5 php artisan db:seed --class=ParapharmacyMultiBranchSeeder
 */
final class ParapharmacyMultiBranchSeeder extends ParapharmacySeeder
{
    /**
     * Central warehouse — bulk inventory, not POS-enabled.
     */
    private Location $warehouse;

    /**
     * Paris shop — POS-enabled.
     */
    private Location $parisShop;

    /**
     * Lyon shop — POS-enabled.
     */
    private Location $lyonShop;

    /**
     * Run the multi-branch seeds.
     *
     * Mirrors ParapharmacySeeder::run() step-for-step (so tenant provisioning
     * for db-per-tenant is identical), but swaps in the multi-location company
     * builder, multi-location stock distribution, per-shop terminals, and
     * per-location user scoping.
     */
    public function run(): void
    {
        $this->command->newLine();
        $this->command->info('🏥 Seeding PharmaBio France - MULTI-BRANCH Parapharmacy demo');
        $this->command->newLine();

        // 1. Tenant FIRST (identical provisioning path — db-per-tenant safe).
        //    In db-per-tenant mode this provisions + migrates the tenant
        //    database and swaps the default connection into it, so reference
        //    data and everything below land in the tenant database.
        $this->command->info('🏢 Creating tenant...');
        $this->tenant = $this->createParapharmacyTenant();
        $this->command->info("✓ Tenant: {$this->tenant->name} (parapharmacy vertical)");

        // 2. Reference data + roles/permissions (shared with single-branch
        //    seeder; now inside tenant context when db-per-tenant is on).
        $this->command->info('📚 Checking reference data...');
        $this->call(RolesAndPermissionsSeeder::class);
        $this->seedReferenceDataIfMissing();
        $this->command->info('✓ Reference data ready');

        // 3. Company + 3 locations (warehouse + 2 POS shops).
        $this->command->info('🏪 Creating company with 3 branches...');
        [$this->company, $this->warehouse, $this->parisShop, $this->lyonShop]
            = $this->createCompanyWithBranches($this->tenant);
        // ParapharmacySeeder keeps a single $location reference; point it at the
        // Paris shop so any inherited helper that reads $this->location has a
        // sensible POS-enabled default.
        $this->location = $this->parisShop;
        $this->command->info("✓ Company: {$this->company->name}");
        $this->command->info("✓ Warehouse: {$this->warehouse->name} (POS disabled)");
        $this->command->info("✓ Shop: {$this->parisShop->name} (POS enabled)");
        $this->command->info("✓ Shop: {$this->lyonShop->name} (POS enabled)");

        // 4. Financial foundation (shared).
        $this->command->info('💰 Setting up financial foundation...');
        $this->setupFinancialFoundation($this->company);

        // 5. Products (shared — 1000 default; SCALE-aware).
        $this->command->info('📦 Seeding products...');
        $products = $this->seedProducts($this->company);
        $this->command->info("✓ Created {$products->count()} products across 6 categories");

        // 6. Partners (shared).
        $this->command->info('👥 Seeding partners...');
        $this->seedPartners($this->tenant, $this->company);

        // 7. Multi-location stock distribution (warehouse bulk + small shop stock).
        $this->command->info('📊 Distributing stock across branches...');
        $this->seedMultiBranchStock($this->company, $products);

        // 8. One POS terminal per shop.
        $this->command->info('🖥️  Creating POS terminals...');
        $this->seedTerminals($this->tenant, $this->company);

        // 9. Base users (owner/manager/cashier, all-location) + per-shop cashiers.
        $this->command->info('👤 Creating users...');
        $this->createTestUsers($this->tenant, $this->company);
        $this->createLocationScopedCashiers($this->tenant, $this->company);

        $this->printDemoSummary();

        // Revert the default connection back to central (no-op in single-DB).
        $this->endTenancy();
    }

    /**
     * Seed shared reference data only when absent (idempotent across re-runs).
     */
    private function seedReferenceDataIfMissing(): void
    {
        if (DB::table('countries')->count() === 0) {
            $this->call(CountriesSeeder::class);
        }
        if (Ingredient::count() === 0) {
            $this->call(IngredientsSeeder::class);
        }
        if (Certification::count() === 0) {
            $this->call(CertificationsSeeder::class);
        }
        if (HealthClaim::count() === 0) {
            $this->call(HealthClaimsSeeder::class);
        }
        if (KeyComponent::count() === 0) {
            $this->call(KeyComponentsSeeder::class);
        }
    }

    /**
     * Create the company plus 3 locations: a central warehouse and 2 POS shops.
     *
     * @return array{0: Company, 1: Location, 2: Location, 3: Location}
     */
    private function createCompanyWithBranches(Tenant $tenant): array
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PharmaBio France SAS',
            'legal_name' => 'PharmaBio France SAS',
            'country_code' => 'FR',
            'tax_id' => 'FR12345678901',
            'vat_number' => 'FR12345678901',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'address_street' => '42 Avenue des Champs-Élysées',
            'address_city' => 'Paris',
            'address_postal_code' => '75008',
            'address_state' => null,
            'phone' => '+33 1 23 45 67 89',
            'email' => 'contact@pharmabio.fr',
        ]);

        $warehouse = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'WH-01',
            'name' => 'PharmaBio Central Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
            'address_street' => '12 Rue de la Logistique',
            'address_city' => 'Roissy-en-France',
            'address_postal_code' => '95700',
            'address_country' => 'FR',
            'phone' => '+33 1 70 00 00 01',
            'email' => 'warehouse@pharmabio.fr',
        ]);

        $parisShop = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'STORE-PAR',
            'name' => 'PharmaBio Paris',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => true,
            'address_street' => '42 Avenue des Champs-Élysées',
            'address_city' => 'Paris',
            'address_postal_code' => '75008',
            'address_country' => 'FR',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'paris@pharmabio.fr',
        ]);

        $lyonShop = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'STORE-LYO',
            'name' => 'PharmaBio Lyon',
            'type' => LocationType::Shop,
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => true,
            'address_street' => '5 Place Bellecour',
            'address_city' => 'Lyon',
            'address_postal_code' => '69002',
            'address_country' => 'FR',
            'phone' => '+33 4 78 00 00 02',
            'email' => 'lyon@pharmabio.fr',
        ]);

        return [$company, $warehouse, $parisShop, $lyonShop];
    }

    /**
     * Distribute stock across the 3 branches.
     *
     * Most inventory lives at the Central Warehouse (the transfer source);
     * each shop carries a small front-of-house quantity so the demo can both
     * sell at a shop AND transfer warehouse → shop to replenish. 90% of
     * products get a warehouse stock row; each shop independently stocks ~60%
     * of products with small quantities.
     *
     * @param  Collection<int, Product>  $products
     */
    private function seedMultiBranchStock(Company $company, Collection $products): void
    {
        $warehouseRows = 0;
        $parisRows = 0;
        $lyonRows = 0;

        foreach ($products as $product) {
            $category = $product->parapharmacyMetadata?->category;

            // Warehouse: bulk stock for 90% of products.
            if (rand(1, 100) <= 90) {
                $this->createStockRow(
                    $company,
                    $this->warehouse,
                    $product,
                    $this->warehouseQuantityFor($category),
                );
                $warehouseRows++;
            }

            // Paris shop: small front-of-house stock for ~60% of products.
            if (rand(1, 100) <= 60) {
                $this->createStockRow(
                    $company,
                    $this->parisShop,
                    $product,
                    (string) rand(2, 15),
                );
                $parisRows++;
            }

            // Lyon shop: small front-of-house stock for ~60% of products.
            if (rand(1, 100) <= 60) {
                $this->createStockRow(
                    $company,
                    $this->lyonShop,
                    $product,
                    (string) rand(2, 15),
                );
                $lyonRows++;
            }
        }

        $this->command->info("✓ Warehouse stock rows: {$warehouseRows}");
        $this->command->info("✓ Paris shop stock rows: {$parisRows}");
        $this->command->info("✓ Lyon shop stock rows:  {$lyonRows}");
    }

    /**
     * Bulk warehouse quantity by category (an order of magnitude above the
     * per-shop front-of-house quantities so transfers make sense).
     */
    private function warehouseQuantityFor(?ParapharmacyCategory $category): string
    {
        $qty = match ($category) {
            ParapharmacyCategory::Supplement => rand(500, 2000),
            ParapharmacyCategory::Cosmetic => rand(500, 1500),
            ParapharmacyCategory::Herbal => rand(200, 1000),
            ParapharmacyCategory::BabyCare => rand(200, 1000),
            ParapharmacyCategory::MedicalDevice => rand(100, 500),
            ParapharmacyCategory::SportsNutrition => rand(100, 500),
            default => rand(100, 1000),
        };

        return (string) $qty;
    }

    /**
     * Insert one stock_levels row for a product at a location.
     */
    private function createStockRow(
        Company $company,
        Location $location,
        Product $product,
        string $quantity,
    ): void {
        StockLevel::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved' => '0',
        ]);
    }

    /**
     * Create one physical POS terminal per shop.
     */
    private function seedTerminals(Tenant $tenant, Company $company): void
    {
        foreach (
            [
                ['location' => $this->parisShop, 'name' => 'Paris Front Counter'],
                ['location' => $this->lyonShop, 'name' => 'Lyon Front Counter'],
            ] as $definition
        ) {
            $location = $definition['location'];

            Terminal::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'location_id' => $location->id,
                'type' => TerminalType::Physical,
                // Unique per (tenant, company, location); CHECK constraint
                // requires the ^POS[0-9]{2}$ format.
                'code' => 'POS01',
                'name' => $definition['name'],
                'genesis_seed' => bin2hex(random_bytes(32)),
                'current_sequence' => 0,
                'current_year' => (int) now()->format('Y'),
                'fiscal_schema_version' => 3,
                'is_active' => true,
                'activated_at' => now(),
            ]);

            $this->command->info("✓ Terminal POS01 @ {$location->name}");
        }
    }

    /**
     * Create two location-scoped cashiers (Paris-only and Lyon-only) so the
     * demo can show per-location isolation. Each cashier's membership pins
     * `allowed_location_ids` to a single shop; owner/manager keep NULL (all
     * locations) so they retain cross-branch transfer ability.
     */
    private function createLocationScopedCashiers(Tenant $tenant, Company $company): void
    {
        setPermissionsTeamId($tenant->id);

        $cashierRole = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->first();

        $definitions = [
            [
                'name' => 'Camille Moreau',
                'email' => 'paris.cashier@pharmabio.fr',
                'pin' => '1111',
                'location' => $this->parisShop,
            ],
            [
                'name' => 'Luc Girard',
                'email' => 'lyon.cashier@pharmabio.fr',
                'pin' => '2222',
                'location' => $this->lyonShop,
            ],
        ];

        foreach ($definitions as $definition) {
            $location = $definition['location'];

            $user = User::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'name' => $definition['name'],
                'email' => $definition['email'],
                'password' => Hash::make('password'),
                'status' => 'active',
                'email_verified_at' => now(),
                'preferences' => [],
            ]);

            // Register the per-shop cashier in the central identity index so
            // email-first login resolves this tenant with no manual backfill.
            $this->recordIdentity($user, $tenant);

            UserCompanyMembership::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'role' => MembershipRole::Cashier,
                'allowed_location_ids' => [$location->id],
                'is_primary' => true,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]);

            if ($cashierRole) {
                $user->assignRole($cashierRole);
            }

            $user->update(['pos_pin' => Hash::make($definition['pin'])]);

            $this->command->info(
                "✓ Cashier {$definition['email']} (PIN {$definition['pin']}) scoped to {$location->name}",
            );
        }
    }

    /**
     * Print the demo operator summary (credentials + location/terminal ids).
     */
    private function printDemoSummary(): void
    {
        $this->command->newLine();
        $this->command->info('✅ Multi-branch parapharmacy demo seeded successfully!');
        $this->command->newLine();

        $this->command->info('🏢 Tenant: '.$this->tenant->name.' ('.$this->tenant->id.')');
        $this->command->info('🏪 Company: '.$this->company->name.' ('.$this->company->id.')');
        $this->command->newLine();

        $this->command->info('📍 Locations:');
        foreach (
            [
                ['loc' => $this->warehouse, 'note' => 'Warehouse, POS disabled (transfer source)'],
                ['loc' => $this->parisShop, 'note' => 'Shop, POS enabled'],
                ['loc' => $this->lyonShop, 'note' => 'Shop, POS enabled'],
            ] as $row
        ) {
            /** @var Location $loc */
            $loc = $row['loc'];
            $this->command->info("   {$loc->code} — {$loc->name} [{$loc->id}] — {$row['note']}");
        }
        $this->command->newLine();

        $this->command->info('🖥️  POS Terminals: POS01 @ Paris, POS01 @ Lyon');
        $this->command->newLine();

        $this->command->info('🔑 Credentials (password: "password" for all):');
        $this->command->info('   Owner (all locations):   owner@pharmabio.fr   / PIN 1234');
        $this->command->info('   Manager (all locations): manager@pharmabio.fr / PIN 5678');
        $this->command->info('   Cashier (all locations): cashier@pharmabio.fr / PIN 0000');
        $this->command->info('   Paris cashier (Paris only): paris.cashier@pharmabio.fr / PIN 1111');
        $this->command->info('   Lyon cashier (Lyon only):   lyon.cashier@pharmabio.fr  / PIN 2222');
        $this->command->newLine();
    }
}
