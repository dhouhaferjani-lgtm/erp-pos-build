<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Vertical;
use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\EquivalenceType;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\SkinType;
use Database\Seeders\Contracts\ChartOfAccountsSeederContract;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * ParapharmacySeeder - Comprehensive seeder for parapharmacy company with EasyPos.
 *
 * Creates a production-ready parapharmacy company with:
 * - 1000 products across 6 categories at the default scale
 *   (T1.0: configurable via PARAPHARMACY_SEEDER_SCALE env var; SCALE=5
 *   produces 5000 products, SCALE=10 produces 10000 — see
 *   `resolveScale()` and `DEFAULT_SCALE`)
 * - Complete ingredient/certification/health claim assignments
 * - Product-level batch tracking (70% of products)
 * - 180 partners (customers and suppliers)
 * - 90% of products with stock levels (scales proportionally)
 * - Complete financial foundation (GL accounts, payment methods)
 * - POS-enabled location
 * - 3 test users (owner, manager, cashier)
 *
 * Usage: php artisan db:seed --class=ParapharmacySeeder
 *        PARAPHARMACY_SEEDER_SCALE=5 php artisan db:seed --class=ParapharmacySeeder
 */
class ParapharmacySeeder extends Seeder
{
    /**
     * Default catalog scale. Multiplies every category count in the
     * `seedProducts` distribution map.
     *
     * Default `1` preserves the historical 1000-product fixture every
     * existing test suite, demo, and CI run depends on. Override at
     * runtime by setting the `PARAPHARMACY_SEEDER_SCALE` env var:
     *   - SCALE=1  → 1000 products (default)
     *   - SCALE=5  → 5000 products (Tier-1/Tier-2 perf-fixture target)
     *   - SCALE=10 → 10000 products
     *
     * Non-numeric or sub-1 values fall back to the default — see
     * `resolveScale()`. T1.0 (parapharmacy fixture for Tier-2 perf
     * assertions: T2.1 paginated catalog warmup, T2.4 Slow-3G smoke).
     */
    private const DEFAULT_SCALE = 1;

    protected Tenant $tenant;

    protected Company $company;

    protected Location $location;

    /**
     * Single writer of the central identity index (`central_identities`).
     *
     * Resolved lazily via {@see identityIndex()} (seeders may use the
     * container; Agent rule 13 — constructor injection only — applies to
     * non-seeder classes). The service is pinned to the central connection,
     * so its writes land in the CENTRAL database in db-per-tenant mode even
     * while the default connection is swapped to the tenant database.
     */
    private ?IdentityIndexService $identityIndexService = null;

    // ==================== Locale hooks ====================
    // Override these in a subclass to localise the seeder for a different
    // country without changing any France behaviour.

    /**
     * ISO 3166-1 alpha-2 country code for the company and tenant.
     */
    protected function localeCountryCode(): string
    {
        return 'FR';
    }

    /**
     * ISO 4217 currency code for the company and tenant.
     */
    protected function localeCurrency(): string
    {
        return 'EUR';
    }

    /**
     * FQCN of the chart-of-accounts seeder to call during financial setup.
     *
     * @return class-string<ChartOfAccountsSeederContract>
     */
    protected function localeChartOfAccountsSeeder(): string
    {
        return FranceChartOfAccountsSeeder::class;
    }

    /**
     * Default VAT rate used when seeding product pricing.
     */
    protected function localeDefaultVatRate(): float
    {
        return 20.00;
    }

    /**
     * EAN-13 barcode prefix (GS1 country code, typically 3 digits).
     */
    protected function localeBarcodePrefix(): string
    {
        return '300';
    }

    /**
     * Partner factory state name applied to customers and suppliers.
     * Must correspond to a named state on the Partner factory (e.g. `france()`).
     */
    protected function localePartnerFactoryState(): string
    {
        return 'france';
    }

    /**
     * Tenant slug used both for existence checks and for tenant creation.
     */
    protected function localeTenantSlug(): string
    {
        return 'pharmabio-france';
    }

    /**
     * Tenant display name stored in the central tenants row.
     */
    protected function localeTenantName(): string
    {
        return 'PharmaBio France';
    }

    /**
     * Tenant tax ID stored in the central tenants row.
     */
    protected function localeTenantTaxId(): string
    {
        return 'FR12345678901';
    }

    /**
     * Tenant timezone stored in the central tenants settings column.
     */
    protected function localeTenantTimezone(): string
    {
        return 'Europe/Paris';
    }

    /**
     * Email domain used for the 3 test user accounts (owner/manager/cashier).
     */
    protected function localeUserEmailDomain(): string
    {
        return 'pharmabio.fr';
    }

    // ==================== Scale helpers ====================

    /**
     * Resolve the catalog scale at run time. Reads the
     * `PARAPHARMACY_SEEDER_SCALE` env var; falls back to DEFAULT_SCALE
     * when the value is missing, non-numeric, or less than 1. The
     * fallback is silent because seeders run in many contexts (CI, demo
     * deploys, local dev) where a hard error on a malformed env var
     * would block unrelated work.
     */
    protected function resolveScale(): int
    {
        // The `?? ?? getenv()` chain returns string|false (getenv returns
        // false when unset), so $raw is never null after the fallback.
        $raw = $_ENV['PARAPHARMACY_SEEDER_SCALE']
            ?? $_SERVER['PARAPHARMACY_SEEDER_SCALE']
            ?? getenv('PARAPHARMACY_SEEDER_SCALE');

        if ($raw === false || $raw === '') {
            return self::DEFAULT_SCALE;
        }

        // filter_var returns int|false. PHPStan level 8 narrows to int
        // after the `=== false` guard; older PHPStan versions don't
        // model that, so we keep the explicit guard for portability
        // (Codex round-1 MINOR-2).
        $scale = filter_var($raw, FILTER_VALIDATE_INT);
        if ($scale === false || $scale < 1) {
            return self::DEFAULT_SCALE;
        }

        return $scale;
    }

    /**
     * Lazily resolve the central identity-index writer.
     *
     * Seeders are allowed to use the container (Agent rule 13 scopes the
     * "constructor injection only" rule to non-seeder classes); the seeder
     * base class has no constructor we can inject into.
     */
    protected function identityIndex(): IdentityIndexService
    {
        return $this->identityIndexService ??= app(IdentityIndexService::class);
    }

    /**
     * Register a created tenant user in the central identity index so
     * email-first (T6) login resolves the tenant for that email with no
     * manual backfill. A null/blank email is a no-op (PIN-only cashiers).
     *
     * `CentralIdentity` is pinned to the central connection, so this write
     * always lands in the CENTRAL database — even in db-per-tenant mode when
     * the default connection has been swapped to the tenant database.
     */
    protected function recordIdentity(User $user, Tenant $tenant): void
    {
        $this->identityIndex()->record($user->email, $tenant->id, $user->id);
    }

    /**
     * Whether database-per-tenant mode is active (the Stancl flip flag). When
     * true the seeder must provision + migrate a physical per-tenant database
     * and run the tenant-scoped writes inside `tenancy()->initialize()`; the
     * central-pinned rows (tenant, subscription, identity index) still land in
     * the central database.
     */
    protected function databasePerTenantEnabled(): bool
    {
        return (bool) config('tenancy_resolver.db_per_tenant', false);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->newLine();
        $this->command->info('🏥 Seeding PharmaBio France - Parapharmacy Company with EasyPos');
        $this->command->newLine();

        // 1. Create tenant FIRST. In db-per-tenant mode this provisions +
        //    migrates the physical tenant database and swaps the default
        //    connection into it, so every tenant-scoped seed below (reference
        //    data, products, users) lands in the tenant database rather than
        //    the central one. In single-DB mode it is just a Tenant::create().
        $this->command->info('🏢 Creating tenant...');
        $this->tenant = $this->createParapharmacyTenant();
        $this->command->info("✓ Tenant: {$this->tenant->name} (parapharmacy vertical)");

        // 2. Ensure reference data exists (now inside tenant context when
        //    db-per-tenant is on — these tables are tenant-scoped).
        $this->command->info('📚 Checking reference data...');

        // Seed roles and permissions first (required for user role assignment)
        $this->call(RolesAndPermissionsSeeder::class);

        // Only seed if data doesn't exist yet
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

        $this->command->info('✓ Reference data ready');

        // 3. Create company with location
        $this->command->info('🏪 Creating company...');
        [$this->company, $this->location] = $this->createCompanyWithLocation($this->tenant);
        $this->command->info("✓ Company: {$this->company->name}");
        $locationSuffix = $this->location->pos_enabled ? '(POS enabled)' : '(warehouse)';
        $this->command->info("✓ Location: {$this->location->name} {$locationSuffix}");

        // 4. Setup financial foundation
        $this->command->info('💰 Setting up financial foundation...');
        $this->setupFinancialFoundation($this->company);

        // 4b. Provision country tax configurations (FR: 5 TVA bands + company default).
        //     Called after CoA + countries (seeded above at step 2) so the
        //     provisioning service can resolve GL accounts and the countries
        //     FK is already in the tenant-scoped connection.
        $companyTaxProvisioning = new CompanyTaxProvisioningService(
            failLoudOnMissingCountry: true,
        );
        $companyTaxProvisioning->provisionForCompany($this->company);
        $this->command->info('✓ Tax configurations provisioned');

        // 5. Seed products (1000 default; T1.0: configurable via SCALE)
        $this->command->info('📦 Seeding products...');
        $products = $this->seedProducts($this->company);
        $this->command->info("✓ Created {$products->count()} products across 6 categories");

        // 5a. Seed brands and assign brand_id to cosmetic / baby-care products
        $this->command->info('🏷️ Seeding brands...');
        $brands = $this->seedBrands($this->company);
        $this->command->info("✓ Created {$brands->count()} brands");

        // 5b. Seed skin suitability mappings for cosmetic products
        $this->command->info('🧴 Seeding skin suitability...');
        $this->seedProductSkinSuitability($this->company);
        $this->command->info('✓ Skin suitability rows seeded');

        // 5c. Seed product equivalents (both directions)
        $this->command->info('🔁 Seeding product equivalents...');
        $this->seedProductEquivalents($this->company);
        $this->command->info('✓ Product equivalents seeded');

        // 5d. Seed product complements (cross-category bundles)
        $this->command->info('🔗 Seeding product complements...');
        $this->seedProductComplements($this->company);
        $this->command->info('✓ Product complements seeded');

        // 5e. Seed skincare routines + ordered membership
        $this->command->info('🧴 Seeding routines...');
        $this->seedRoutines($this->company);
        $this->command->info('✓ Routines seeded');

        // 6. Seed partners (customers and suppliers)
        $this->command->info('👥 Seeding partners...');
        $this->seedPartners($this->tenant, $this->company);

        // 7. Seed stock levels
        $this->command->info('📊 Seeding stock levels...');
        $this->seedStockLevels($this->company, $this->location, $products);

        // 8. Create test users
        $this->command->info('👤 Creating test users...');
        $this->createTestUsers($this->tenant, $this->company);

        $this->command->newLine();
        $this->command->info('✅ Parapharmacy company seeded successfully!');
        $this->command->newLine();
        $d = $this->localeUserEmailDomain();
        $this->command->info('🔑 Test Credentials:');
        $this->command->info("   Owner:   owner@{$d} / password");
        $this->command->info("   Manager: manager@{$d} / password");
        $this->command->info("   Cashier: cashier@{$d} / password");
        $this->command->newLine();

        // Revert the default connection back to central (no-op in single-DB).
        $this->endTenancy();
    }

    /**
     * Create a parapharmacy tenant.
     */
    protected function createParapharmacyTenant(): Tenant
    {
        // Check if tenant already exists
        $slug = $this->localeTenantSlug();
        $existingTenant = Tenant::where('slug', $slug)->first();
        if ($existingTenant) {
            $this->command->warn('⚠ Tenant '.$slug.' already exists. Deleting and recreating...');

            // In db-per-tenant mode the central row delete does NOT drop the
            // physical tenant database (no TenantDeleted -> DeleteDatabase event
            // is wired), so drop it explicitly first to keep re-runs clean.
            if ($this->databasePerTenantEnabled()) {
                try {
                    DB::purge('tenant');
                    $existingTenant->database()->manager()->deleteDatabase($existingTenant);
                } catch (\Throwable) {
                    // best-effort: the database may not have been provisioned.
                }
            }

            // Delete existing tenant and all related data (cascading)
            $existingTenant->delete();
        }

        // Ensure professional plan exists
        $professionalPlan = Plan::where('code', 'professional')->first();
        if ($professionalPlan === null) {
            $this->call(PlansSeeder::class);
            $professionalPlan = Plan::where('code', 'professional')->first();
        }

        $tenant = Tenant::create([
            'name' => $this->localeTenantName(),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => 'professional',
            'vertical' => Vertical::Parapharmacy,
            'tax_id' => $this->localeTenantTaxId(),
            'country_code' => $this->localeCountryCode(),
            'currency_code' => $this->localeCurrency(),
            // Top-level column — this is what CompanyConfigService reads.
            // (It previously sat inside `settings`, which nothing reads, so
            // the demo tenant silently ran with zero extras.)
            // Demo tenant gets EVERY compatible extra so testers can see all
            // gated surfaces (owner decision 2026-06-12).
            'enabled_extras' => ['BatchExpiry', 'Loyalty', 'Ecommerce'],
            'settings' => [
                'timezone' => $this->localeTenantTimezone(),
                'locale' => 'fr',
                'date_format' => 'd/m/Y',
                'fiscal_year_start' => '01-01',
            ],
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
        ]);

        // Create subscription
        if ($professionalPlan) {
            TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $professionalPlan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => 'yearly',
                'price' => 0,
                'current_period_start' => now(),
                'current_period_end' => now()->addYear(),
            ]);
        }

        // db-per-tenant: provision + migrate the physical tenant database and
        // swap the default connection into it so every subsequent tenant-scoped
        // seed (reference data, company, products, users) lands in the tenant
        // database. Central-pinned rows above (tenant, subscription) and the
        // identity index below stay in the central database. Mirrors the
        // canonical registration path in TenantProvisioningService.
        $this->provisionTenantDatabase($tenant);

        return $tenant;
    }

    /**
     * Provision + migrate the per-tenant database and enter tenant context.
     *
     * No-op in single-DB mode: the default connection already serves every
     * table, so there is nothing to create or swap.
     */
    protected function provisionTenantDatabase(Tenant $tenant): void
    {
        if (! $this->databasePerTenantEnabled()) {
            return;
        }

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        // Swap the default connection to the freshly migrated tenant database.
        tenancy()->initialize($tenant);

        $this->command->info("✓ Provisioned tenant database: {$tenant->database()->getName()}");
    }

    /**
     * Revert the default connection to central. No-op in single-DB mode (and
     * when tenancy was never initialized).
     */
    protected function endTenancy(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /**
     * Create company with POS-enabled location.
     *
     * @return array{0: Company, 1: Location}
     */
    protected function createCompanyWithLocation(Tenant $tenant): array
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PharmaBio France SAS',
            'legal_name' => 'PharmaBio France SAS',
            'country_code' => $this->localeCountryCode(),
            'tax_id' => 'FR12345678901',
            'vat_number' => 'FR12345678901',
            'currency' => $this->localeCurrency(),
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

        $location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $company->id,
            'code' => 'STORE-01',
            'name' => 'PharmaBio Paris Centre',
            'type' => 'shop',
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true, // Enable POS for this location
            'address_street' => '42 Avenue des Champs-Élysées',
            'address_city' => 'Paris',
            'address_postal_code' => '75008',
            'address_country' => 'FR',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'contact@pharmabio.fr',
        ]);

        return [$company, $location];
    }

    /**
     * Setup financial foundation (GL accounts, payment methods, repositories).
     */
    protected function setupFinancialFoundation(Company $company): void
    {
        // Chart of accounts (locale-specific seeder, France default)
        $coaSeederClass = $this->localeChartOfAccountsSeeder();
        /** @var ChartOfAccountsSeederContract $coaSeeder */
        $coaSeeder = new $coaSeederClass;
        $coaSeeder->setCommand($this->command);
        $coaSeeder->run($company->id, $company->tenant_id);
        $this->command->info('✓ Chart of Accounts (120 accounts)');

        // Payment methods
        $this->call(PaymentMethodSeeder::class, false, ['company' => $company]);
        $this->command->info('✓ Payment Methods (6 methods)');

        // Payment repositories
        $this->call(PaymentRepositorySeeder::class, false, ['company' => $company]);
        $this->command->info('✓ Payment Repositories (6 repositories)');
    }

    /**
     * Seed 1000+ products across 6 parapharmacy categories.
     *
     * @return Collection<int, Product>
     */
    protected function seedProducts(Company $company): Collection
    {
        // TODO(go-live-followup): bulk-insert refactor for SCALE>1 (deferred from
        //   T1.0 PR #89). seedProducts currently does one Eloquent ::create per
        //   product; at SCALE=10 (10000 products) this is the slowest part of
        //   the smoke fixture. Switching to chunked DB::insert would cut
        //   seeding time meaningfully without changing the data shape. See
        //   docs/superpowers/plans/2026-05-08-pos-t1.0-large-catalog-fixture-kickoff-prompt.md.
        // TODO(go-live-followup): extract a shared SCALE trait or base class
        //   so other seeders (e.g. CoffeeShopSeeder, DemoTenantSeeder) can opt
        //   into the same env-var multiplier without copy-pasting the
        //   resolveScale + DEFAULT_SCALE plumbing. Deferred from T1.0 PR #89.
        // TODO(go-live-followup): CSV-driven product import (deferred from
        //   T1.0 PR #89 / kickoff "Out of scope"). Today fixture growth means
        //   editing the category distribution map in code; a CSV-driven
        //   importer would let ops change the smoke-fixture composition
        //   without a code change + re-deploy. See
        //   docs/superpowers/plans/2026-05-08-pos-t1.0-large-catalog-fixture-kickoff-prompt.md
        //   §"Out of scope" line "CSV-driven product import".
        // Load reference data
        $ingredients = Ingredient::all();
        $certifications = Certification::all();
        $healthClaims = HealthClaim::all();
        $keyComponents = KeyComponent::all();

        $products = collect();
        $categoryCounters = [];

        // T1.0: catalog scale multiplies every category count
        // proportionally. Default (SCALE=1) preserves the historical
        // 1000-product mix every existing CI run depends on. The
        // resolver silently falls back to 1 on invalid input so seeders
        // never fail on a malformed env var.
        $scale = $this->resolveScale();

        // T1.0 Codex round-1 BLOCKER-1: barcodes were generated from a
        // per-category counter that started at 1, producing cross-
        // category collisions (supplement #1, cosmetic #1, etc. all map
        // to the same EAN-13). At SCALE=1 the existing seeder already
        // emitted ~950 duplicates; at SCALE=5 the duplicate count
        // approached 4000, defeating the fixture's purpose as a scan-
        // key corpus for catalog perf tests. Thread a global ordinal
        // through `createProduct` so every product gets a unique
        // barcode regardless of category or scale.
        $globalOrdinal = 0;

        // Category distribution (default counts; multiplied by $scale).
        $distribution = [
            'supplement' => ['category' => ParapharmacyCategory::Supplement, 'count' => 350 * $scale],
            'cosmetic' => ['category' => ParapharmacyCategory::Cosmetic, 'count' => 250 * $scale],
            'medical_device' => ['category' => ParapharmacyCategory::MedicalDevice, 'count' => 100 * $scale],
            'herbal' => ['category' => ParapharmacyCategory::Herbal, 'count' => 150 * $scale],
            'baby_care' => ['category' => ParapharmacyCategory::BabyCare, 'count' => 100 * $scale],
            'sports_nutrition' => ['category' => ParapharmacyCategory::SportsNutrition, 'count' => 50 * $scale],
        ];

        foreach ($distribution as $key => $data) {
            $category = $data['category'];
            $count = $data['count'];
            $categoryCounters[$key] = 0;
            $this->command->info("  Creating {$category->label()} products...");

            for ($i = 0; $i < $count; $i++) {
                $categoryCounters[$key]++;
                $globalOrdinal++;
                $product = $this->createProduct($company, $category, $categoryCounters[$key], $globalOrdinal);
                $products->push($product);

                // Assign relationships
                $this->assignIngredients($product, $category, $ingredients);
                $this->assignCertifications($product, $category, $certifications);
                $this->assignHealthClaims($product, $category, $healthClaims);
                $this->assignKeyComponents($product, $category, $keyComponents);
            }

            $this->command->info("  ✓ {$category->label()} ({$count})");
        }

        return $products;
    }

    /**
     * Seed real French parapharmacy brands and assign brand_id to products.
     *
     * Inserts ~21 real brands via a single batch insert, then distributes
     * brand assignments across products by category (round-robin), setting
     * `brand_source = 'user'` on every assigned row.
     *
     * @return Collection<int, array{id: string, slug: string}>
     */
    protected function seedBrands(Company $company): Collection
    {
        $now = now();
        $tenantId = $company->tenant_id;

        // Real French parapharmacy brands, grouped by category hint used for
        // the assignment heuristic below. `category_hint` mirrors the
        // ParapharmacyCategory::value that these brands are best known for.
        $brandData = [
            // Cosmetic & Skincare
            ['name' => 'Avène',          'country' => 'FR', 'url' => 'https://www.eau-thermale-avene.fr', 'category_hint' => 'cosmetic'],
            ['name' => 'La Roche-Posay', 'country' => 'FR', 'url' => 'https://www.laroche-posay.fr',     'category_hint' => 'cosmetic'],
            ['name' => 'Bioderma',       'country' => 'FR', 'url' => 'https://www.bioderma.fr',           'category_hint' => 'cosmetic'],
            ['name' => 'Vichy',          'country' => 'FR', 'url' => 'https://www.vichy.fr',              'category_hint' => 'cosmetic'],
            ['name' => 'CeraVe',         'country' => 'US', 'url' => 'https://www.cerave.fr',             'category_hint' => 'cosmetic'],
            ['name' => 'Nuxe',           'country' => 'FR', 'url' => 'https://www.nuxe.com',              'category_hint' => 'cosmetic'],
            ['name' => 'Caudalie',       'country' => 'FR', 'url' => 'https://www.caudalie.com',          'category_hint' => 'cosmetic'],
            ['name' => 'Uriage',         'country' => 'FR', 'url' => 'https://www.uriage.com',            'category_hint' => 'cosmetic'],
            ['name' => 'Ducray',         'country' => 'FR', 'url' => 'https://www.ducray.com',            'category_hint' => 'cosmetic'],
            ['name' => 'A-Derma',        'country' => 'FR', 'url' => 'https://www.a-derma.fr',            'category_hint' => 'cosmetic'],
            ['name' => 'Klorane',        'country' => 'FR', 'url' => 'https://www.klorane.com',           'category_hint' => 'cosmetic'],
            ['name' => 'SVR',            'country' => 'FR', 'url' => 'https://www.laboratoiresvr.com',    'category_hint' => 'cosmetic'],
            ['name' => 'Embryolisse',    'country' => 'FR', 'url' => 'https://www.embryolisse.com',       'category_hint' => 'cosmetic'],
            // Baby care
            ['name' => 'Mustela',        'country' => 'FR', 'url' => 'https://www.mustela.com',           'category_hint' => 'baby_care'],
            ['name' => 'Bébé Cadum',     'country' => 'FR', 'url' => null,                                'category_hint' => 'baby_care'],
            // Dietary supplements
            ['name' => 'Pileje',         'country' => 'FR', 'url' => 'https://www.pileje.com',            'category_hint' => 'supplement'],
            ['name' => 'Nutergia',       'country' => 'FR', 'url' => 'https://www.nutergia.com',          'category_hint' => 'supplement'],
            ['name' => 'Forté Pharma',   'country' => 'FR', 'url' => 'https://www.fortepharma.com',       'category_hint' => 'supplement'],
            ['name' => 'Boiron',         'country' => 'FR', 'url' => 'https://www.boiron.fr',             'category_hint' => 'supplement'],
            // Herbal / phytotherapy
            ['name' => 'Arkopharma',     'country' => 'FR', 'url' => 'https://www.arkopharma.com',        'category_hint' => 'herbal'],
            ['name' => 'Weleda',         'country' => 'DE', 'url' => 'https://www.weleda.fr',             'category_hint' => 'herbal'],
        ];

        // Build flat rows for a single batch insert
        $rows = [];
        /** @var array<string, array{id: string, category_hint: string}> */
        $brandsBySlug = [];

        foreach ($brandData as $data) {
            $id = Str::uuid()->toString();
            $slug = Brand::slugFor($data['name']);

            $rows[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'slug' => $slug,
                'canonical_brand_id' => null,
                'logo_media_id' => null,
                'website_url' => $data['url'],
                'country_of_origin' => $data['country'],
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $brandsBySlug[$slug] = ['id' => $id, 'category_hint' => $data['category_hint']];
        }

        DB::table('brands')->insert($rows);

        // Assign brands to products by category using round-robin distribution.
        // Only categories that have matching brands are processed; others are
        // left with brand_id = null (medical_device, sports_nutrition, etc.).
        $brandSource = BrandSource::User->value;

        foreach (['cosmetic', 'baby_care', 'supplement', 'herbal'] as $categoryHint) {
            $categoryBrandIds = array_column(
                array_filter(
                    $brandsBySlug,
                    fn (array $b): bool => $b['category_hint'] === $categoryHint,
                ),
                'id',
            );

            if ($categoryBrandIds === []) {
                continue;
            }

            /** @var list<string> $productIds */
            $productIds = DB::table('parapharmacy_product_metadata')
                ->join('products', 'products.id', '=', 'parapharmacy_product_metadata.product_id')
                ->where('products.company_id', $company->id)
                ->where('parapharmacy_product_metadata.category', $categoryHint)
                ->pluck('parapharmacy_product_metadata.product_id')
                ->toArray();

            if ($productIds === []) {
                continue;
            }

            // Group product IDs by brand (round-robin), then one UPDATE per brand.
            $brandCount = count($categoryBrandIds);
            $groupedByBrand = [];

            foreach ($productIds as $idx => $productId) {
                $brandId = $categoryBrandIds[$idx % $brandCount];
                $groupedByBrand[$brandId][] = $productId;
            }

            foreach ($groupedByBrand as $brandId => $ids) {
                DB::table('products')
                    ->whereIn('id', $ids)
                    ->update([
                        'brand_id' => $brandId,
                        'brand_source' => $brandSource,
                    ]);
            }
        }

        /** @var Collection<int, array{id: string, slug: string}> */
        return collect($rows)->map(fn (array $r): array => ['id' => $r['id'], 'slug' => $r['slug']]);
    }

    /**
     * Seed skin suitability mappings for cosmetic/visage products.
     *
     * Maps each cosmetic product to 1–3 {@see SkinType} values using a
     * round-robin heuristic over 6 predefined combinations, then
     * batch-inserts the rows into `product_skin_suitability`. Rows are
     * chunked at 150 per insert to stay within SQLite's 999-variable
     * prepared-statement limit (6 columns × 150 = 900 < 999).
     *
     * The unique(product_id, skin_type) constraint is respected because
     * each combination contains no repeated SkinType values.
     */
    protected function seedProductSkinSuitability(Company $company): void
    {
        // 6 predefined combinations spanning all 5 SkinType cases.
        // Round-robin assignment ensures every product gets 1–3 distinct
        // skin types and the full type space appears in the fixture.
        /** @var list<list<SkinType>> $combinations */
        $combinations = [
            [SkinType::Normal, SkinType::Dry],
            [SkinType::Sensitive, SkinType::Normal],
            [SkinType::Oily, SkinType::Combination],
            [SkinType::Dry, SkinType::Sensitive],
            [SkinType::Combination, SkinType::Normal, SkinType::Oily],
            [SkinType::Sensitive, SkinType::Dry, SkinType::Normal],
        ];

        $tenantId = $company->tenant_id;
        $now = now();
        $combinationCount = count($combinations);

        /** @var list<string> $productIds */
        $productIds = DB::table('parapharmacy_product_metadata')
            ->join('products', 'products.id', '=', 'parapharmacy_product_metadata.product_id')
            ->where('products.company_id', $company->id)
            ->where('parapharmacy_product_metadata.category', ParapharmacyCategory::Cosmetic->value)
            ->pluck('parapharmacy_product_metadata.product_id')
            ->toArray();

        if ($productIds === []) {
            return;
        }

        $rows = [];

        foreach ($productIds as $idx => $productId) {
            /** @var list<SkinType> $types */
            $types = $combinations[$idx % $combinationCount];

            foreach ($types as $skinType) {
                $rows[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'skin_type' => $skinType->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 6 columns × 150 rows = 900 params — safely under SQLite's 999 limit.
        foreach (array_chunk($rows, 150) as $chunk) {
            DB::table('product_skin_suitability')->insert($chunk);
        }
    }

    /**
     * Create a single product with appropriate metadata.
     */
    private function createProduct(Company $company, ParapharmacyCategory $category, int $counter, int $globalOrdinal): Product
    {
        $productName = $this->generateProductName($category, $counter);
        $dosageForm = $this->getDosageForm($category);
        $sku = $this->generateSKU($category, $counter);
        // T1.0 Codex round-1 BLOCKER-1: barcode now uses the global
        // ordinal so cross-category collisions (supplement #1 vs
        // cosmetic #1) and SCALE > 1 multiplications don't generate
        // duplicate EANs.
        // Routed through productBarcode() hook so locale subclasses can
        // produce a null subset (e.g. Tunisia demo: 30% no-barcode).
        $barcode = $this->productBarcode($globalOrdinal);

        // Pricing
        [$retailPrice, $cost, $vatRate] = $this->calculatePricing($category);

        // Batch tracking (70% of products)
        $requiresBatchTracking = rand(1, 100) <= 70;
        $shelfLifeDays = $requiresBatchTracking ? $this->getShelfLife($category) : null;

        $product = Product::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => $productName,
            'sku' => $sku,
            'barcode' => $barcode,
            'is_physical' => true,
            'purchase_price' => $cost,
            'sale_price' => $retailPrice,
            'tax_rate' => $vatRate,
            'is_active' => true,
            'is_physical' => true,
            'requires_batch_tracking' => $requiresBatchTracking,
            'default_shelf_life_days' => $shelfLifeDays,
        ]);

        // Create parapharmacy metadata
        ParapharmacyProductMetadata::create([
            'product_id' => $product->id,
            'category' => $category,
            'dosage_form' => $dosageForm,
            'usage_instructions' => $this->getUsageInstructions($category, $dosageForm),
            'warnings' => $this->getWarnings($category),
            'contraindications' => $this->getContraindications($category),
            'minimum_age' => $this->getMinimumAge($category),
            'age_restriction' => AgeRestriction::AllAges,
            'requires_consultation' => $category->typicallyRequiresConsultation(),
            'regulatory_code' => null,
            'storage_requirements' => $this->getStorageRequirements($category),
        ]);

        return $product;
    }

    /**
     * Assign ingredients to a product based on category.
     */
    private function assignIngredients(
        Product $product,
        ParapharmacyCategory $category,
        Collection $ingredients
    ): void {
        // Determine how many ingredients to assign
        $ingredientCount = match ($category) {
            ParapharmacyCategory::Supplement => rand(2, 5),
            ParapharmacyCategory::Herbal => rand(1, 3),
            ParapharmacyCategory::SportsNutrition => rand(1, 4),
            default => 0,
        };

        if ($ingredientCount === 0) {
            return;
        }

        $selectedIngredients = $ingredients->random(min($ingredientCount, $ingredients->count()));
        $order = 1;

        foreach ($selectedIngredients as $ingredient) {
            [$concentration, $concentrationNumeric, $unit] = $this->generateConcentration();

            DB::table('product_ingredient')->insert([
                'id' => Str::uuid()->toString(),
                'product_id' => $product->id,
                'ingredient_id' => $ingredient->id,
                'concentration' => $concentration,
                'concentration_numeric' => $concentrationNumeric,
                'concentration_unit' => $unit,
                'order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Assign certifications to a product based on category.
     */
    private function assignCertifications(
        Product $product,
        ParapharmacyCategory $category,
        Collection $certifications
    ): void {
        // 60% of products get certifications
        if (rand(1, 100) > 60) {
            return;
        }

        // Get probability of certifications by type for this category
        $certTypes = $this->getCertificationProbabilities($category);

        foreach ($certifications as $certification) {
            $certSlug = $certification->slug ?? '';
            $probability = $certTypes[$certSlug] ?? 0;

            if ($probability > 0 && rand(1, 100) <= $probability) {
                $issuedDate = now()->subMonths(rand(1, 12));
                $validityMonths = match ($certSlug) {
                    'organic', 'bio' => 24,
                    'halal', 'kosher', 'iso-22000', 'gmp' => 36,
                    'gluten-free' => 12,
                    default => 24,
                };

                DB::table('certification_product')->insert([
                    'id' => Str::uuid()->toString(),
                    'product_id' => $product->id,
                    'certification_id' => $certification->id,
                    'certification_code' => 'BIO-FR-'.date('Y').'-'.str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
                    'issued_date' => $issuedDate,
                    'expiry_date' => $issuedDate->copy()->addMonths($validityMonths),
                    'verification_url' => 'https://ecocert.com/verify/'.Str::random(12),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Assign health claims to a product based on category.
     */
    private function assignHealthClaims(
        Product $product,
        ParapharmacyCategory $category,
        Collection $healthClaims
    ): void {
        // Probability of health claims by category
        $probability = match ($category) {
            ParapharmacyCategory::Supplement => 80,
            ParapharmacyCategory::Herbal => 70,
            ParapharmacyCategory::SportsNutrition => 60,
            default => 10,
        };

        if (rand(1, 100) > $probability) {
            return;
        }

        // Number of health claims to assign
        $claimCount = match ($category) {
            ParapharmacyCategory::Supplement => rand(1, 3),
            ParapharmacyCategory::Herbal => rand(1, 2),
            ParapharmacyCategory::SportsNutrition => rand(1, 2),
            default => 1,
        };

        $selectedClaims = $healthClaims->random(min($claimCount, $healthClaims->count()));
        $order = 1;

        foreach ($selectedClaims as $claim) {
            DB::table('health_claim_product')->insert([
                'id' => Str::uuid()->toString(),
                'product_id' => $product->id,
                'health_claim_id' => $claim->id,
                'display_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Assign key components to a product based on dosage form.
     */
    private function assignKeyComponents(
        Product $product,
        ParapharmacyCategory $category,
        Collection $keyComponents
    ): void {
        $metadata = $product->parapharmacyMetadata;
        if (! $metadata) {
            return;
        }

        $componentsToAssign = [];

        // Assign based on dosage form
        $dosageForm = $metadata->dosage_form;

        switch ($dosageForm) {
            case DosageForm::Capsule:
                // 60% gelatin, 40% vegetarian
                $capsuleType = rand(1, 100) <= 60 ? 'gelatin-capsule' : 'vegetarian-capsule';
                $componentsToAssign[] = $capsuleType;
                break;

            case DosageForm::Tablet:
                $componentsToAssign[] = 'microcrystalline-cellulose';
                $componentsToAssign[] = 'magnesium-stearate';
                $componentsToAssign[] = 'silica';
                break;

            case DosageForm::Softgel:
                $componentsToAssign[] = 'gelatin-capsule';
                $componentsToAssign[] = 'glycerin';
                break;

            case DosageForm::Cream:
            case DosageForm::Lotion:
                if ($category === ParapharmacyCategory::Cosmetic) {
                    $componentsToAssign[] = 'hyaluronic-acid';
                }
                break;
        }

        // Common components (anti-caking, preservatives)
        if (in_array($dosageForm, [DosageForm::Tablet, DosageForm::Capsule, DosageForm::Powder])) {
            if (rand(1, 100) <= 50) {
                $componentsToAssign[] = 'stearic-acid';
            }
        }

        $order = 1;
        foreach ($componentsToAssign as $slug) {
            $component = $keyComponents->firstWhere('slug', $slug);
            if ($component) {
                DB::table('key_component_product')->insert([
                    'id' => Str::uuid()->toString(),
                    'product_id' => $product->id,
                    'component_id' => $component->id,
                    'order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Seed partners (customers and suppliers).
     */
    protected function seedPartners(Tenant $tenant, Company $company): void
    {
        $localeState = $this->localePartnerFactoryState();

        // 150 individual customers (B2C)
        Partner::factory()
            ->count(150)
            ->customer()
            ->{$localeState}()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
        $this->command->info('✓ Individual Customers (150)');

        // 20 corporate customers (B2B - nursing homes, clinics)
        // Distinguished by having VAT number, notes indicating corporate nature
        Partner::factory()
            ->count(20)
            ->customer()
            ->{$localeState}()
            ->state([
                'vat_number' => fn () => 'FR'.str_pad((string) rand(10000000000, 99999999999), 11, '0', STR_PAD_LEFT),
                'notes' => 'Corporate customer (nursing home/clinic)',
            ])
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
        $this->command->info('✓ Corporate Customers (20)');

        // 10 suppliers (pharmaceutical wholesalers)
        Partner::factory()
            ->count(10)
            ->supplier()
            ->{$localeState}()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
        $this->command->info('✓ Suppliers (10)');
    }

    /**
     * Seed stock levels for 90% of products.
     */
    protected function seedStockLevels(
        Company $company,
        Location $location,
        Collection $products
    ): void {
        $stockCount = 0;

        foreach ($products as $product) {
            // 90% of products have stock
            if (rand(1, 100) > 90) {
                continue;
            }

            $metadata = $product->parapharmacyMetadata;
            $quantity = match ($metadata->category) {
                ParapharmacyCategory::Supplement => rand(50, 200),
                ParapharmacyCategory::Cosmetic => rand(50, 150),
                ParapharmacyCategory::Herbal => rand(20, 100),
                ParapharmacyCategory::BabyCare => rand(20, 100),
                ParapharmacyCategory::MedicalDevice => rand(10, 50),
                ParapharmacyCategory::SportsNutrition => rand(10, 50),
                default => rand(10, 100),
            };

            StockLevel::create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'product_id' => $product->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
                'reserved' => 0,
            ]);

            $stockCount++;
        }

        $this->command->info("✓ Stock levels created ({$stockCount} products in stock)");
    }

    /**
     * Create test users (owner, manager, cashier).
     */
    protected function createTestUsers(Tenant $tenant, Company $company): void
    {
        // Set the team (tenant) context for Spatie permissions
        setPermissionsTeamId($tenant->id);

        // 1. Owner Account
        $emailDomain = $this->localeUserEmailDomain();
        $owner = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Jean-Baptiste Mercier',
            'email' => 'owner@'.$emailDomain,
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
        ]);

        // Register in the central identity index so email-first login resolves
        // this tenant for owner@pharmabio.fr with no manual backfill.
        $this->recordIdentity($owner, $tenant);

        UserCompanyMembership::create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $adminRole = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $owner->assignRole($adminRole);
        }

        $owner->update(['pos_pin' => Hash::make('1234')]);

        $this->command->info("✓ Owner: owner@{$emailDomain} (PIN: 1234)");

        // 2. Manager Account
        $manager = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Sophie Laurent',
            'email' => 'manager@'.$emailDomain,
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
        ]);

        $this->recordIdentity($manager, $tenant);

        UserCompanyMembership::create([
            'user_id' => $manager->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Manager,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $managerRole = Role::where('name', 'manager')->where('guard_name', 'sanctum')->first();
        if ($managerRole) {
            $manager->assignRole($managerRole);
        }

        $manager->update(['pos_pin' => Hash::make('5678')]);

        $this->command->info("✓ Manager: manager@{$emailDomain} (PIN: 5678)");

        // 3. Cashier Account (POS-only permissions)
        $cashier = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Marie Dubois',
            'email' => 'cashier@'.$emailDomain,
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'preferences' => [],
        ]);

        $this->recordIdentity($cashier, $tenant);

        UserCompanyMembership::create([
            'user_id' => $cashier->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $cashierRole = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->first();
        if ($cashierRole) {
            $cashier->assignRole($cashierRole);
        }

        $cashier->update(['pos_pin' => Hash::make('0000')]);

        $this->command->info("✓ Cashier: cashier@{$emailDomain} (PIN: 0000)");
    }

    // ==================== Helper Methods ====================

    /**
     * Generate a realistic product name based on category and counter.
     */
    private function generateProductName(ParapharmacyCategory $category, int $counter): string
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => $this->generateSupplementName($counter),
            ParapharmacyCategory::Cosmetic => $this->generateCosmeticName($counter),
            ParapharmacyCategory::MedicalDevice => $this->generateMedicalDeviceName($counter),
            ParapharmacyCategory::Herbal => $this->generateHerbalName($counter),
            ParapharmacyCategory::BabyCare => $this->generateBabyCareName($counter),
            ParapharmacyCategory::SportsNutrition => $this->generateSportsNutritionName($counter),
            default => "Product {$counter}",
        };
    }

    private function generateSupplementName(int $counter): string
    {
        $supplements = [
            'Vitamin D3 1000 IU - 60 Capsules',
            'Omega-3 Fish Oil 1000mg - 90 Softgels',
            'Magnesium Citrate 400mg - 120 Tablets',
            'Multivitamin Complex - 30 Tablets',
            'Zinc Picolinate 50mg - 60 Capsules',
            'Probiotic 10 Billion CFU - 30 Capsules',
            'CoQ10 100mg - 60 Softgels',
            'Vitamin B Complex - 60 Tablets',
            'Iron Bisglycinate 25mg - 90 Tablets',
            'Calcium + Vitamin D3 - 120 Tablets',
        ];

        return $supplements[$counter % count($supplements)].($counter >= count($supplements) ? " #{$counter}" : '');
    }

    private function generateCosmeticName(int $counter): string
    {
        $cosmetics = [
            'Anti-Aging Serum Hyaluronic Acid 30ml',
            'Moisturizing Face Cream 50ml',
            'Sunscreen SPF 50+ 100ml',
            'Cleansing Gel Sensitive Skin 200ml',
            'Night Cream Collagen Boost 50ml',
            'Eye Contour Cream 15ml',
            'Micellar Water 400ml',
            'Body Lotion Shea Butter 250ml',
            'Hand Cream Repair 75ml',
            'Lip Balm SPF 15 4.5g',
        ];

        return $cosmetics[$counter % count($cosmetics)].($counter >= count($cosmetics) ? " #{$counter}" : '');
    }

    private function generateMedicalDeviceName(int $counter): string
    {
        $devices = [
            'Digital Thermometer',
            'Blood Pressure Monitor',
            'Glucose Meter Kit',
            'Pulse Oximeter',
            'Compression Socks Class II',
            'Hot Water Bottle',
            'Cold/Hot Gel Pack',
            'Elastic Bandage 10cm x 4m',
            'Wound Dressing 10x10cm (box of 10)',
            'Insulin Pen Needles 32G (box of 100)',
        ];

        return $devices[$counter % count($devices)].($counter >= count($devices) ? " #{$counter}" : '');
    }

    private function generateHerbalName(int $counter): string
    {
        $herbals = [
            'Echinacea Extract 500mg - 60 Capsules',
            'Ginkgo Biloba 120mg - 90 Tablets',
            'Milk Thistle Extract 175mg - 60 Capsules',
            'Valerian Root 500mg - 60 Capsules',
            'St. John\'s Wort Extract - 60 Tablets',
            'Ginger Root Extract 500mg - 90 Capsules',
            'Turmeric Curcumin 500mg - 120 Capsules',
            'Green Tea Extract 500mg - 60 Capsules',
            'Ashwagandha 600mg - 60 Capsules',
            'Rhodiola Rosea 500mg - 60 Capsules',
        ];

        return $herbals[$counter % count($herbals)].($counter >= count($herbals) ? " #{$counter}" : '');
    }

    private function generateBabyCareName(int $counter): string
    {
        $babyCare = [
            'Baby Shampoo Gentle Formula 200ml',
            'Diaper Rash Cream 100g',
            'Baby Oil Sweet Almond 200ml',
            'Baby Lotion Hydrating 200ml',
            'Baby Wipes Sensitive (box of 72)',
            'Vitamin D Drops for Infants 10ml',
            'Nasal Aspirator',
            'Baby Thermometer',
            'Teething Gel 15ml',
            'Baby Sunscreen SPF 50+ 100ml',
        ];

        return $babyCare[$counter % count($babyCare)].($counter >= count($babyCare) ? " #{$counter}" : '');
    }

    private function generateSportsNutritionName(int $counter): string
    {
        $sports = [
            'Whey Protein Isolate Vanilla 900g',
            'BCAA 2:1:1 Powder 300g',
            'Creatine Monohydrate 500g',
            'Pre-Workout Energy 300g',
            'Post-Workout Recovery 600g',
            'Protein Bar Chocolate 60g',
            'Electrolyte Powder 400g',
            'L-Carnitine 1000mg - 60 Capsules',
            'Glutamine Powder 300g',
            'Energy Gel Orange 32g (box of 24)',
        ];

        return $sports[$counter % count($sports)].($counter >= count($sports) ? " #{$counter}" : '');
    }

    /**
     * Get appropriate dosage form for category.
     */
    private function getDosageForm(ParapharmacyCategory $category): ?DosageForm
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => [
                DosageForm::Capsule,
                DosageForm::Tablet,
                DosageForm::Softgel,
                DosageForm::Powder,
            ][rand(0, 3)],

            ParapharmacyCategory::Cosmetic => [
                DosageForm::Cream,
                DosageForm::Gel,
                DosageForm::Lotion,
                DosageForm::Liquid,
                DosageForm::Spray,
            ][rand(0, 4)],

            ParapharmacyCategory::MedicalDevice => null,

            ParapharmacyCategory::Herbal => [
                DosageForm::Capsule,
                DosageForm::Tablet,
                DosageForm::Liquid,
            ][rand(0, 2)],

            ParapharmacyCategory::BabyCare => [
                DosageForm::Liquid,
                DosageForm::Cream,
                DosageForm::Lotion,
            ][rand(0, 2)],

            ParapharmacyCategory::SportsNutrition => [
                DosageForm::Powder,
                DosageForm::Capsule,
                DosageForm::Liquid,
            ][rand(0, 2)],

            default => null,
        };
    }

    /**
     * Generate SKU based on category.
     */
    private function generateSKU(ParapharmacyCategory $category, int $counter): string
    {
        $prefix = match ($category) {
            ParapharmacyCategory::Supplement => 'PB-SUP',
            ParapharmacyCategory::Cosmetic => 'PB-COS',
            ParapharmacyCategory::MedicalDevice => 'PB-MED',
            ParapharmacyCategory::Herbal => 'PB-HER',
            ParapharmacyCategory::BabyCare => 'PB-BAB',
            ParapharmacyCategory::SportsNutrition => 'PB-SPO',
            default => 'PB-OTH',
        };

        return $prefix.'-'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Return the barcode to assign to a catalog product by its global ordinal,
     * or null to leave the product without a barcode.
     *
     * Default (France) behaviour: always assign a barcode via
     * {@see generateBarcode()}. Override in a locale subclass to produce a
     * mixed barcode/null catalog (e.g. Tunisia demo: 30% null).
     *
     * The ordinal passed here is the same $globalOrdinal threaded through
     * {@see seedProducts()} — unique per product, 1-based, deterministic.
     */
    protected function productBarcode(int $ordinal): ?string
    {
        return $this->generateBarcode($ordinal);
    }

    /**
     * Generate valid EAN-13 barcode.
     */
    protected function generateBarcode(int $counter): string
    {
        // GS1 barcode prefix (3 digits, locale-specific) + 9-digit ordinal
        $base = $this->localeBarcodePrefix().str_pad((string) ($counter % 1000000000), 9, '0', STR_PAD_LEFT);

        // Calculate check digit
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $base.$checkDigit;
    }

    /**
     * Calculate pricing (retail price, cost, VAT rate).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function calculatePricing(ParapharmacyCategory $category): array
    {
        // Retail price ranges by category (in EUR)
        $retailPrice = match ($category) {
            ParapharmacyCategory::Supplement => rand(800, 4500) / 100,
            ParapharmacyCategory::Cosmetic => rand(1200, 8500) / 100,
            ParapharmacyCategory::MedicalDevice => rand(1500, 12000) / 100,
            ParapharmacyCategory::Herbal => rand(1000, 3800) / 100,
            ParapharmacyCategory::BabyCare => rand(500, 2500) / 100,
            ParapharmacyCategory::SportsNutrition => rand(1800, 6500) / 100,
            default => rand(1000, 5000) / 100,
        };

        // Margin: 30-60%
        $margin = rand(30, 60) / 100;
        $cost = $retailPrice * (1 - $margin);

        // VAT rate — locale default for all parapharmacy categories. Supplements,
        // baby care, and medical devices are NOT reimbursed medicines, so the
        // standard rate applies in the demo context (task T15). Real pharmacies
        // may negotiate reduced rates, but the demo must show non-zero tax
        // matching the provisioned default TaxConfiguration.
        $vatRate = $this->localeDefaultVatRate();

        return [$retailPrice, $cost, $vatRate];
    }

    /**
     * Get shelf life in days for batch-tracked products.
     */
    private function getShelfLife(ParapharmacyCategory $category): int
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => 730, // 2 years
            ParapharmacyCategory::Cosmetic => 1095, // 3 years
            ParapharmacyCategory::MedicalDevice => 1825, // 5 years
            ParapharmacyCategory::Herbal => 545, // 1.5 years
            ParapharmacyCategory::BabyCare => 730, // 2 years
            ParapharmacyCategory::SportsNutrition => 545, // 1.5 years
            default => 730,
        };
    }

    /**
     * Get usage instructions based on category.
     */
    private function getUsageInstructions(ParapharmacyCategory $category, ?DosageForm $dosageForm): string
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => 'Take 1-2 capsules daily with water, preferably with a meal.',
            ParapharmacyCategory::Cosmetic => 'Apply to clean skin morning and evening. Massage gently until fully absorbed.',
            ParapharmacyCategory::MedicalDevice => 'Follow manufacturer instructions included in package. Consult healthcare provider if unsure.',
            ParapharmacyCategory::Herbal => 'Take 1-2 capsules daily, preferably between meals or as directed by healthcare practitioner.',
            ParapharmacyCategory::BabyCare => 'For external use only. Apply gently to affected area. Avoid contact with eyes.',
            ParapharmacyCategory::SportsNutrition => 'Mix 1-2 scoops with 300ml water. Consume 30 minutes before or after workout.',
            default => 'Use as directed.',
        };
    }

    /**
     * Get warnings based on category.
     */
    private function getWarnings(ParapharmacyCategory $category): string
    {
        return match ($category) {
            ParapharmacyCategory::Supplement,
            ParapharmacyCategory::Herbal => 'Consult your doctor if pregnant, nursing, or taking medication. Do not exceed recommended dose.',
            ParapharmacyCategory::Cosmetic => 'For external use only. Discontinue use if irritation occurs. Avoid contact with eyes.',
            ParapharmacyCategory::MedicalDevice => 'Medical device. Follow instructions carefully. Single use only if indicated.',
            ParapharmacyCategory::BabyCare => 'Keep out of reach of children. For external use only. Test on small area first.',
            ParapharmacyCategory::SportsNutrition => 'Not intended for use by persons under 18. Consult healthcare professional before use.',
            default => 'Keep out of reach of children.',
        };
    }

    /**
     * Get contraindications based on category.
     */
    private function getContraindications(ParapharmacyCategory $category): string
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => 'Not suitable for children under 12 years. Consult healthcare provider if on medication.',
            ParapharmacyCategory::Cosmetic => 'Do not use on broken or irritated skin. Patch test recommended.',
            ParapharmacyCategory::MedicalDevice => 'Consult healthcare provider if symptoms persist. Not for reuse.',
            ParapharmacyCategory::Herbal => 'Not suitable for children, pregnant or nursing women without medical advice.',
            ParapharmacyCategory::BabyCare => 'Not for ingestion. Avoid contact with eyes and mucous membranes.',
            ParapharmacyCategory::SportsNutrition => 'Not suitable for pregnant or nursing women. Consult doctor if under treatment.',
            default => 'Consult healthcare provider before use.',
        };
    }

    /**
     * Get minimum age based on category.
     */
    private function getMinimumAge(ParapharmacyCategory $category): ?int
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => 12,
            ParapharmacyCategory::Herbal => 12,
            ParapharmacyCategory::SportsNutrition => 18,
            default => null,
        };
    }

    /**
     * Get storage requirements based on category.
     */
    private function getStorageRequirements(ParapharmacyCategory $category): string
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => 'Store in a cool, dry place away from direct sunlight. Keep out of reach of children.',
            ParapharmacyCategory::Cosmetic => 'Store at room temperature. Avoid extreme heat or cold. Keep container tightly closed.',
            ParapharmacyCategory::MedicalDevice => 'Store according to manufacturer instructions. Keep in original packaging.',
            ParapharmacyCategory::Herbal => 'Store in a cool, dry place. Do not refrigerate. Keep away from moisture.',
            ParapharmacyCategory::BabyCare => 'Store at room temperature. Keep out of reach of children. For external use only.',
            ParapharmacyCategory::SportsNutrition => 'Store in a cool, dry place. Reseal after use. Use within 3 months of opening.',
            default => 'Store in a cool, dry place.',
        };
    }

    /**
     * Generate concentration data for ingredients.
     *
     * @return array{0: string, 1: float, 2: string}
     */
    private function generateConcentration(): array
    {
        $types = [
            ['1000 IU', 1000.0, 'IU'],
            ['500 mg', 500.0, 'mg'],
            ['100 mcg', 100.0, 'mcg'],
            ['200 mg', 200.0, 'mg'],
            ['1000 mg', 1000.0, 'mg'],
            ['50 mg', 50.0, 'mg'],
            ['25 mg', 25.0, 'mg'],
        ];

        return $types[rand(0, count($types) - 1)];
    }

    /**
     * Seed French skincare routines and their ordered product memberships.
     *
     * Inserts 3 named routines into `routines` and 3–4 ordered step rows per
     * routine into `product_routine`, picking one cosmetic product per step
     * from a deterministic dosage-form bucket:
     *   Gel     → "Nettoyage"
     *   Liquid  → "Sérum"
     *   Cream   → "Hydratation intense" / "Protection"
     *   Lotion  → "Hydratation légère" / "Hydratation"
     *   Spray   → "Protection solaire"
     *
     * The unique(routine_id, product_id) constraint is respected by
     * construction: every step within a routine draws from a **different**
     * dosage-form pool, so a product cannot appear twice in the same routine.
     *
     * Rows are chunked at 100 (`routines`) and 150 (`product_routine`) to
     * stay under SQLite's 999-parameter prepared-statement limit.
     */
    protected function seedRoutines(Company $company): void
    {
        $tenantId = $company->tenant_id;
        $now = now();

        // ── 1. Resolve one product pool per cosmetic dosage form ───────────
        // Ordered deterministically (by product_id) so the seeder is stable
        // across re-runs.  Each pool maps dosage_form_value → [product_id, …].
        /** @var array<string, list<string>> $byForm */
        $byForm = [];

        foreach (['gel', 'liquid', 'cream', 'lotion', 'spray'] as $form) {
            /** @var list<string> $ids */
            $ids = DB::table('parapharmacy_product_metadata as m')
                ->join('products as p', 'p.id', '=', 'm.product_id')
                ->where('p.company_id', $company->id)
                ->where('m.category', ParapharmacyCategory::Cosmetic->value)
                ->where('m.dosage_form', $form)
                ->orderBy('m.product_id')
                ->pluck('m.product_id')
                ->toArray();

            $byForm[$form] = $ids;
        }

        // ── 2. Insert 3 routine header rows ───────────────────────────────
        /** @var list<array{id: string, tenant_id: string, name: string, description: string, period: string, is_active: bool, created_at: mixed, updated_at: mixed}> $routineRows */
        $routineRows = [
            [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'name' => 'Routine visage — peau sèche',
                'description' => 'Routine quotidienne adaptée aux peaux sèches et inconfortables.',
                'period' => 'matin-soir',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'name' => 'Routine visage — peau grasse',
                'description' => 'Routine légère pour les peaux grasses et à tendance acnéique.',
                'period' => 'matin-soir',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'name' => 'Routine visage — peau sensible',
                'description' => 'Routine douce pour les peaux réactives et sensibles.',
                'period' => 'matin-soir',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        // 8 columns × 3 rows = 24 params — well under SQLite's 999 limit.
        foreach (array_chunk($routineRows, 100) as $chunk) {
            DB::table('routines')->insert($chunk);
        }

        // ── 3. Build product_routine membership rows ───────────────────────
        // Routine 0 "peau sèche"   : 4 steps — gel[0], liquid[0], cream[0], spray[0]
        // Routine 1 "peau grasse"  : 3 steps — gel[1], liquid[1], lotion[0]
        // Routine 2 "peau sensible": 4 steps — gel[2], liquid[2], lotion[1], cream[1]
        //
        // Each step draws from a DISTINCT dosage-form pool so product_id
        // never repeats within the same routine.

        /** @var list<array{ri: int, form: string, idx: int, label: string}> $stepDefs */
        $stepDefs = [
            // Routine 0 — peau sèche (4 steps)
            ['ri' => 0, 'form' => 'gel',    'idx' => 0, 'label' => 'Nettoyage'],
            ['ri' => 0, 'form' => 'liquid', 'idx' => 0, 'label' => 'Sérum actif'],
            ['ri' => 0, 'form' => 'cream',  'idx' => 0, 'label' => 'Hydratation intense'],
            ['ri' => 0, 'form' => 'spray',  'idx' => 0, 'label' => 'Protection solaire'],
            // Routine 1 — peau grasse (3 steps)
            ['ri' => 1, 'form' => 'gel',    'idx' => 1, 'label' => 'Nettoyage purifiant'],
            ['ri' => 1, 'form' => 'liquid', 'idx' => 1, 'label' => 'Sérum régulateur'],
            ['ri' => 1, 'form' => 'lotion', 'idx' => 0, 'label' => 'Hydratation légère'],
            // Routine 2 — peau sensible (4 steps)
            ['ri' => 2, 'form' => 'gel',    'idx' => 2, 'label' => 'Nettoyage doux'],
            ['ri' => 2, 'form' => 'liquid', 'idx' => 2, 'label' => 'Sérum apaisant'],
            ['ri' => 2, 'form' => 'lotion', 'idx' => 1, 'label' => 'Hydratation'],
            ['ri' => 2, 'form' => 'cream',  'idx' => 1, 'label' => 'Protection'],
        ];

        /** @var list<array{id: string, routine_id: string, product_id: string, step_order: int, step_label: string, created_at: mixed, updated_at: mixed}> $memberRows */
        $memberRows = [];

        /** @var array<int, int> $stepCounters — routine index → next step_order (1-based) */
        $stepCounters = [];

        foreach ($stepDefs as $def) {
            $ri = $def['ri'];
            $pool = $byForm[$def['form']] ?? [];

            // Skip step gracefully when the dosage-form pool is empty; the
            // remaining steps stay contiguous because we only increment the
            // counter when we actually write a row.
            if ($pool === []) {
                continue;
            }

            $productId = $pool[$def['idx'] % count($pool)];
            $routineId = $routineRows[$ri]['id'];

            if (! isset($stepCounters[$ri])) {
                $stepCounters[$ri] = 1;
            }

            $memberRows[] = [
                'id' => Str::uuid()->toString(),
                'routine_id' => $routineId,
                'product_id' => $productId,
                'step_order' => $stepCounters[$ri],
                'step_label' => $def['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $stepCounters[$ri]++;
        }

        // 6 columns × 150 rows = 900 params — safely under SQLite's 999 limit.
        foreach (array_chunk($memberRows, 150) as $chunk) {
            DB::table('product_routine')->insert($chunk);
        }
    }

    /**
     * Seed product equivalents (generic / brand-alt) in both directions.
     *
     * Groups products by (category, dosage_form) sub-group and links up to 3
     * consecutive pairs within each sub-group as equivalents. Every forward
     * row (A→B) is immediately followed by its reverse (B→A) with the same
     * `equivalence_type`, satisfying the symmetry invariant.
     *
     * Safety guarantees enforced in PHP (the SQLite test DB does not run the
     * PostgreSQL CHECK constraint):
     *  - `product_id != equivalent_product_id` (self-ref guard)
     *  - `unique(product_id, equivalent_product_id)` (dedup via `$seen`)
     *
     * Rows are chunked at 100 to stay under SQLite's 999-parameter limit
     * (8 columns × 100 = 800).
     */
    protected function seedProductEquivalents(Company $company): void
    {
        $tenantId = $company->tenant_id;
        $now = now();

        // Pull product IDs ordered deterministically per (category, dosage_form) sub-group.
        /** @var Collection<string, Collection<int, object{product_id: string}>> $groups */
        $groups = DB::table('parapharmacy_product_metadata as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('p.company_id', $company->id)
            ->whereNotNull('m.dosage_form')
            ->select('m.category', 'm.dosage_form', 'm.product_id')
            ->orderBy('m.product_id')
            ->get()
            ->groupBy(fn (object $row): string => $row->category.'|'.$row->dosage_form);

        /** @var list<string> $validTypes */
        $validTypes = [EquivalenceType::Generic->value, EquivalenceType::BrandAlt->value];
        $typeIndex = 0;

        /** @var list<array{id: string, tenant_id: string, product_id: string, equivalent_product_id: string, equivalence_type: string, notes: null, created_at: mixed, updated_at: mixed}> $rows */
        $rows = [];

        /** @var array<string, true> $seen — deduplicate unordered pairs across groups */
        $seen = [];

        foreach ($groups as $productCollection) {
            // Limit to 4 candidates per sub-group so the seeder stays lean.
            /** @var list<string> $ids */
            $ids = $productCollection->take(4)->pluck('product_id')->toArray();

            if (count($ids) < 2) {
                continue;
            }

            $type = $validTypes[$typeIndex % count($validTypes)];
            $typeIndex++;

            // Consecutive pairs: (0,1), (1,2), (2,3) — at most 3 pairs per sub-group.
            for ($i = 0, $n = count($ids) - 1; $i < $n; $i++) {
                $a = $ids[$i];
                $b = $ids[$i + 1];

                // Self-reference guard (impossible with distinct product IDs, but defensive).
                if ($a === $b) {
                    continue;
                }

                // Dedup across groups: store canonical (min, max) key.
                $key = ($a < $b) ? $a.'|'.$b : $b.'|'.$a;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                // Forward direction A → B
                $rows[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'product_id' => $a,
                    'equivalent_product_id' => $b,
                    'equivalence_type' => $type,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Reverse direction B → A (same type — symmetry)
                $rows[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'product_id' => $b,
                    'equivalent_product_id' => $a,
                    'equivalence_type' => $type,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 8 columns × 100 rows = 800 params — safely under SQLite's 999 limit.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('product_equivalents')->insert($chunk);
        }
    }

    /**
     * Seed product complements as cross-category bundles.
     *
     * Resolves one representative product per parapharmacy category and creates
     * directed complement links across pre-defined category pairs (e.g. supplement
     * paired with cosmetic, herbal paired with supplement, etc.). Every row must
     * link products from different categories — enforced by construction and
     * verified by the test.
     *
     * Rows are chunked at 140 to stay under SQLite's 999-parameter limit
     * (7 columns × 140 = 980).
     */
    protected function seedProductComplements(Company $company): void
    {
        $tenantId = $company->tenant_id;
        $now = now();

        // One representative product ID per category (lexicographic minimum UUID).
        /** @var array<string, string> $firstByCategory — category value => product_id */
        $firstByCategory = DB::table('parapharmacy_product_metadata as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('p.company_id', $company->id)
            ->select('m.category', DB::raw('MIN(m.product_id) as product_id'))
            ->groupBy('m.category')
            ->pluck('product_id', 'category')
            ->all();

        // Cross-category pairs that form clinically meaningful bundles:
        // supplement + cosmetic (e.g. vitamin D + SPF sunscreen),
        // herbal + supplement (e.g. echinacea + zinc),
        // baby_care + supplement (e.g. baby lotion + vitamin D drops),
        // sports_nutrition + supplement (e.g. creatine + magnesium),
        // cosmetic + herbal (e.g. face cream + plant extract),
        // medical_device + supplement (e.g. blood glucose meter + chromium).
        /** @var list<array{0: string, 1: string}> $pairs */
        $pairs = [
            ['supplement', 'cosmetic'],
            ['herbal', 'supplement'],
            ['baby_care', 'supplement'],
            ['sports_nutrition', 'supplement'],
            ['cosmetic', 'herbal'],
            ['medical_device', 'supplement'],
        ];

        /** @var list<array{id: string, tenant_id: string, product_id: string, complement_product_id: string, reason: null, created_at: mixed, updated_at: mixed}> $rows */
        $rows = [];

        /** @var array<string, true> $seen — dedup unordered pairs */
        $seen = [];

        foreach ($pairs as [$catA, $catB]) {
            $a = $firstByCategory[$catA] ?? null;
            $b = $firstByCategory[$catB] ?? null;

            if ($a === null || $b === null || $a === $b) {
                continue;
            }

            $key = ($a < $b) ? $a.'|'.$b : $b.'|'.$a;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'product_id' => $a,
                'complement_product_id' => $b,
                'reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // 7 columns × 140 rows = 980 params — safely under SQLite's 999 limit.
        foreach (array_chunk($rows, 140) as $chunk) {
            DB::table('product_complements')->insert($chunk);
        }
    }

    /**
     * Get certification probabilities by category.
     *
     * @return array<string, int>
     */
    private function getCertificationProbabilities(ParapharmacyCategory $category): array
    {
        return match ($category) {
            ParapharmacyCategory::Supplement => [
                'organic' => 30,
                'bio' => 30,
                'halal' => 25,
                'kosher' => 20,
                'vegan' => 40,
                'gluten-free' => 30,
            ],
            ParapharmacyCategory::Cosmetic => [
                'organic' => 50,
                'bio' => 50,
                'vegan' => 60,
                'cruelty-free' => 70,
            ],
            ParapharmacyCategory::MedicalDevice => [
                'iso-22000' => 80,
                'gmp' => 70,
            ],
            ParapharmacyCategory::Herbal => [
                'organic' => 70,
                'bio' => 70,
                'halal' => 30,
                'vegan' => 50,
            ],
            ParapharmacyCategory::BabyCare => [
                'organic' => 80,
                'bio' => 80,
                'hypoallergenic' => 60,
            ],
            ParapharmacyCategory::SportsNutrition => [
                'halal' => 20,
                'vegan' => 30,
                'gluten-free' => 40,
            ],
            default => [],
        };
    }
}
