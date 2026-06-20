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
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * DemoPharmacySeeder — Tunisia parapharmacy demo fixture.
 *
 * Extends {@see ParapharmacySeeder} to provision a Tunisia-localised
 * parapharmacy company (PharmaBio Tunisie SARL, TND/TN) under the
 * tenant slug `demo-pharmacy-tn`.
 *
 * Task 2 scope: tenant + company (TN/TND) + central warehouse (WH-01) +
 * Tunisia COA (via {@see TunisiaChartOfAccountsSeeder}) + Tunisia tax config
 * (VAT 19/13/7 + stamp duties via {@see TunisiaTaxConfigurationSeeder}).
 *
 * Task 3 scope: extends {@see createCompanyWithLocation()} to add 4 POS shops
 * (STORE-TUN1, STORE-TUN2, STORE-SOU, STORE-SFA) each with a per-establishment
 * matricule fiscal. Available to Tasks 4/6/9 via {@see $shops}.
 */
final class DemoPharmacySeeder extends ParapharmacySeeder
{
    /**
     * The 4 Tunisia POS shop locations created by {@see createCompanyWithLocation()}.
     *
     * Populated after the parent's company-creation step completes.
     * Available to Tasks 4/6/9 that need to seed per-shop data.
     *
     * @var Location[]
     */
    protected array $shops = [];

    // ==================== Locale hooks ====================

    protected function localeCountryCode(): string
    {
        return 'TN';
    }

    protected function localeCurrency(): string
    {
        return 'TND';
    }

    /**
     * @return class-string<\Database\Seeders\Contracts\ChartOfAccountsSeederContract>
     */
    protected function localeChartOfAccountsSeeder(): string
    {
        return TunisiaChartOfAccountsSeeder::class;
    }

    protected function localeDefaultVatRate(): float
    {
        return 19.00;
    }

    protected function localeBarcodePrefix(): string
    {
        return '619';
    }

    protected function localePartnerFactoryState(): string
    {
        return 'tunisia';
    }

    protected function localeTenantSlug(): string
    {
        return 'demo-pharmacy-tn';
    }

    protected function localeTenantName(): string
    {
        return 'PharmaBio Tunisie SARL';
    }

    protected function localeTenantTaxId(): string
    {
        return '1234567AM000';
    }

    protected function localeTenantTimezone(): string
    {
        return 'Africa/Tunis';
    }

    protected function localeUserEmailDomain(): string
    {
        return 'pharmabio.tn';
    }

    // ==================== Barcode policy override ====================

    /**
     * Tunisia demo barcode policy: ~30% of catalog products get NO barcode
     * (null) so the enrichment demo exercises the "platform performs lookup/
     * assignment" path. The remaining ~70% get a `619`-prefixed EAN-13 via
     * the parent, which already calls {@see localeBarcodePrefix()} = '619'.
     *
     * Deterministic by ordinal (no randomness) so the test count is stable
     * across repeated seeder runs and CI is reproducible.
     *
     * Ratio: ordinals where `$ordinal % 10 < 3` → null (ordinals 0,1,2 in
     * each decade = 30%); ordinals 3-9 → parent (619-prefixed EAN-13 = 70%).
     */
    protected function productBarcode(int $ordinal): ?string
    {
        if ($ordinal % 10 < 3) {
            return null;
        }

        return parent::productBarcode($ordinal);
    }

    // ==================== Company creation override ====================

    /**
     * Create the Tunisia company with a central warehouse + 4 POS shops.
     *
     * Overrides the France-hardcoded values in the parent's
     * {@see ParapharmacySeeder::createCompanyWithLocation()} method to set
     * the full Tunisia identity (name, address in Sousse, matricule fiscal,
     * TN/TND currency), a non-POS warehouse location (WH-01), and 4 POS
     * shop locations each carrying a per-establishment matricule fiscal.
     *
     * The warehouse is returned as the parent's primary `$this->location`
     * so the parent's stock/products seeding still works. The 4 shops are
     * exposed via {@see $shops} for use by Tasks 4/6/9.
     *
     * Per-establishment matricule pattern: `1234567AM00{n}` satisfies the
     * CountryTaxNumberRules TN regex `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/`.
     *
     * @return array{0: Company, 1: Location}
     */
    protected function createCompanyWithLocation(Tenant $tenant): array
    {
        $company = Company::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'PharmaBio Tunisie SARL'],
            [
                'legal_name' => 'PharmaBio Tunisie SARL',
                'country_code' => $this->localeCountryCode(),
                'tax_id' => '1234567AM000',
                'vat_number' => '1234567AM000',
                'currency' => $this->localeCurrency(),
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'date_format' => 'd/m/Y',
                'fiscal_year_start_month' => 1,
                'status' => CompanyStatus::Active,
                'is_headquarters' => true,
                'address_street' => '12 Avenue Habib Bourguiba',
                'address_city' => 'Sousse',
                'address_postal_code' => '4000',
                'address_state' => 'Sousse',
                'phone' => '+216 73 000 000',
                'email' => 'contact@pharmabio.tn',
            ]
        );

        // Central warehouse — non-POS, tax_id NULL (inherits company matricule).
        $warehouse = Location::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'WH-01'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'PharmaBio Entrepôt Central',
                'type' => LocationType::Warehouse,
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => false,
                'tax_id' => null, // inherits company matricule
                'address_street' => '12 Avenue Habib Bourguiba',
                'address_city' => 'Sousse',
                'address_postal_code' => '4000',
                'address_country' => 'TN',
                'phone' => '+216 73 000 000',
                'email' => 'warehouse@pharmabio.tn',
            ]
        );

        // 4 POS shops with per-establishment matricule fiscal.
        // Pattern: 1234567AM00{n} — satisfies CountryTaxNumberRules TN regex.
        $this->shops = $this->seedTunisiaShops($company);

        return [$company, $warehouse];
    }

    /**
     * Create the 4 Tunisia POS shop locations with per-establishment matricule.
     *
     * Ported from {@see ParapharmacyMultiBranchSeeder::createCompanyWithBranches()}
     * (lines 251-297), adapted to Tunisia identity and 4-shop topology.
     * Uses `firstOrCreate` keyed on `(company_id, code)` so re-runs are safe.
     *
     * @return Location[]
     */
    private function seedTunisiaShops(Company $company): array
    {
        $shopDefinitions = [
            [
                'code' => 'STORE-TUN1',
                'name' => 'PharmaBio Tunis — Lac',
                'city' => 'Tunis',
                'postal_code' => '1053',
                'street' => '15 Rue du Lac de Constance',
                'tax_id' => '1234567AM001',
                'establishment_code' => '001',
                'phone' => '+216 71 100 001',
                'email' => 'tunis-lac@pharmabio.tn',
            ],
            [
                'code' => 'STORE-TUN2',
                'name' => 'PharmaBio Tunis — Centre',
                'city' => 'Tunis',
                'postal_code' => '1000',
                'street' => '3 Avenue Habib Bourguiba',
                'tax_id' => '1234567AM002',
                'establishment_code' => '002',
                'phone' => '+216 71 100 002',
                'email' => 'tunis-centre@pharmabio.tn',
            ],
            [
                'code' => 'STORE-SOU',
                'name' => 'PharmaBio Sousse — Médina',
                'city' => 'Sousse',
                'postal_code' => '4000',
                'street' => '7 Rue Ali Belhouane',
                'tax_id' => '1234567AM003',
                'establishment_code' => '003',
                'phone' => '+216 73 100 003',
                'email' => 'sousse-medina@pharmabio.tn',
            ],
            [
                'code' => 'STORE-SFA',
                'name' => 'PharmaBio Sfax — Centre',
                'city' => 'Sfax',
                'postal_code' => '3000',
                'street' => '22 Avenue Habib Bourguiba',
                'tax_id' => '1234567AM004',
                'establishment_code' => '004',
                'phone' => '+216 74 100 004',
                'email' => 'sfax-centre@pharmabio.tn',
            ],
        ];

        $shops = [];
        foreach ($shopDefinitions as $def) {
            $shops[] = Location::firstOrCreate(
                ['company_id' => $company->id, 'code' => $def['code']],
                [
                    'id' => Str::uuid()->toString(),
                    'name' => $def['name'],
                    'type' => LocationType::Shop,
                    'is_default' => false,
                    'is_active' => true,
                    'pos_enabled' => true,
                    'address_street' => $def['street'],
                    'address_city' => $def['city'],
                    'address_postal_code' => $def['postal_code'],
                    'address_country' => 'TN',
                    'tax_id' => $def['tax_id'],
                    'legal_identifiers' => [
                        'matricule_fiscal' => $def['tax_id'],
                        'establishment_code' => $def['establishment_code'],
                    ],
                    'phone' => $def['phone'],
                    'email' => $def['email'],
                ]
            );
        }

        return $shops;
    }

    // ==================== run() ====================

    /**
     * Run the Tunisia demo seeds.
     *
     * Calls the parent {@see ParapharmacySeeder::run()} which:
     *   1. Creates the tenant (slug `demo-pharmacy-tn`) via {@see createParapharmacyTenant()}.
     *   2. Seeds reference data (roles, countries, ingredients, etc.).
     *   3. Creates the company + warehouse via our overridden
     *      {@see createCompanyWithLocation()} (TN identity).
     *   4. Calls {@see setupFinancialFoundation()} which invokes the Tunisia
     *      COA seeder via {@see localeChartOfAccountsSeeder()}.
     *   5. Provisions company tax via {@see CompanyTaxProvisioningService}
     *      (this seeds TN VAT bands from the tax_configurations table seeded
     *      by {@see TunisiaTaxConfigurationSeeder} below).
     *   6. Seeds products, partners, stock, and users.
     *
     * After the parent completes we additionally run
     * {@see TunisiaTaxConfigurationSeeder} inside the tenant context to
     * ensure VAT 19/13/7 + stamp duties are always present regardless of
     * whether the parent's provisioning service found matching rows.
     */
    public function run(): void
    {
        parent::run();

        // Ensure Tunisia tax configs (VAT 19/13/7 + stamp duties) are seeded.
        // TunisiaTaxConfigurationSeeder uses updateOrCreate so it is idempotent
        // and safe to run after the parent's CompanyTaxProvisioningService call.
        $tenant = Tenant::where('slug', $this->localeTenantSlug())->firstOrFail();
        $tenant->run(function (): void {
            $this->call(TunisiaTaxConfigurationSeeder::class);
            $this->seedTunisiaTerminals($this->shops);
            $this->seedTunisiaCashiers($this->company, $this->shops);

            // Distribute front-of-house stock to the 4 POS shops.
            // The warehouse (WH-01) already holds broad stock from the parent's
            // seedStockLevels() — only the shops are seeded here.
            $products = Product::where('company_id', $this->company->id)->get();
            $this->seedTunisiaStock($this->company, $products);
        });
    }

    /**
     * Distribute front-of-house stock to the 4 Tunisia POS shops.
     *
     * Strategy mirrors {@see ParapharmacyMultiBranchSeeder::seedMultiBranchStock}:
     * each shop independently gets ~60% of the catalog in small quantities (2–15
     * units). The warehouse (WH-01) stock was already seeded by the parent's
     * {@see ParapharmacySeeder::seedStockLevels()} — this method ONLY touches
     * the shop locations so warehouse stock is not duplicated.
     *
     * Additive / re-run-safe: uses {@see StockLevel::updateOrCreate} keyed on
     * the non-variant partial-unique constraint `(tenant_id, product_id, location_id)`
     * with `variant_id = NULL`, matching the SQLite/PG `stock_levels_non_variant`
     * partial index from migration `2026_06_02_100005_add_variant_id_to_stock_levels`.
     *
     * Quantities are integers cast to string (no float casts; precision is
     * `decimal(12,4)` but front-of-house whole-unit quantities need no bcmath
     * precision here — the parent's `seedStockLevels` also uses plain integers).
     *
     * @param  Collection<int, Product>  $products
     */
    protected function seedTunisiaStock(Company $company, Collection $products): void
    {
        /** @var array<string, int> $shopRowCounts */
        $shopRowCounts = [];

        foreach ($this->shops as $shop) {
            $shopRowCounts[$shop->code] = 0;
        }

        foreach ($products as $product) {
            $category = $product->parapharmacyMetadata?->category;

            foreach ($this->shops as $shop) {
                // ~60% of products land at each shop (independent rolls per shop).
                if (rand(1, 100) > 60) {
                    continue;
                }

                $qty = $this->shopQuantityFor($category);

                // Additive: update if already seeded (re-run safety), create otherwise.
                // The non-variant unique key is (tenant_id, product_id, location_id)
                // WHERE variant_id IS NULL — reflected here by omitting variant_id.
                StockLevel::updateOrCreate(
                    [
                        'tenant_id'   => $company->tenant_id,
                        'product_id'  => $product->id,
                        'location_id' => $shop->id,
                        'variant_id'  => null,
                    ],
                    [
                        'company_id' => $company->id,
                        'quantity'   => $qty,
                        'reserved'   => '0',
                    ],
                );

                $shopRowCounts[$shop->code]++;
            }
        }

        foreach ($shopRowCounts as $code => $count) {
            $this->command->info("✓ {$code} shop stock rows: {$count}");
        }
    }

    /**
     * Small front-of-house quantity by category for a POS shop.
     *
     * Medical devices and sports nutrition are slower-moving: 1–5 units.
     * Everything else: 2–15 units. No floats — plain integer cast to string.
     */
    private function shopQuantityFor(?ParapharmacyCategory $category): string
    {
        $qty = match ($category) {
            ParapharmacyCategory::MedicalDevice,
            ParapharmacyCategory::SportsNutrition => rand(1, 5),
            default                                => rand(2, 15),
        };

        return (string) $qty;
    }

    /**
     * Create one POS01 terminal per Tunisia shop, unclaimed (hardware_identifier NULL)
     * so devices can claim them immediately on first launch.
     *
     * @param Location[] $shops
     */
    protected function seedTunisiaTerminals(array $shops): void
    {
        foreach ($shops as $shop) {
            Terminal::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'location_id' => $shop->id,
                    'code' => 'POS01',
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenant->id,
                    'type' => TerminalType::Physical,
                    'name' => $shop->name.' — POS01',
                    'genesis_seed' => bin2hex(random_bytes(32)),
                    'current_sequence' => 0,
                    'current_year' => (int) now()->format('Y'),
                    'fiscal_schema_version' => 3,
                    'is_active' => true,
                    'activated_at' => now(),
                    // hardware_identifier intentionally NULL — device claims on first launch
                ],
            );
        }
    }

    /**
     * Create one location-scoped cashier per Tunisia shop.
     *
     * Each cashier's membership pins allowed_location_ids to a single shop so
     * the demo shows per-location isolation. Owner/manager (seeded by the parent)
     * retain NULL (all locations) and are not touched here.
     *
     * @param Location[] $shops
     */
    protected function seedTunisiaCashiers(Company $company, array $shops): void
    {
        setPermissionsTeamId($this->tenant->id);

        $cashierRole = Role::where('name', 'cashier')->where('guard_name', 'sanctum')->first();

        $domain = $this->localeUserEmailDomain();

        $definitions = [
            'STORE-TUN1' => ['email' => "tunis1.cashier@{$domain}", 'pin' => '1111', 'name' => 'Caissier Tunis Lac'],
            'STORE-TUN2' => ['email' => "tunis2.cashier@{$domain}", 'pin' => '2222', 'name' => 'Caissier Tunis Centre'],
            'STORE-SOU'  => ['email' => "sousse.cashier@{$domain}", 'pin' => '3333', 'name' => 'Caissier Sousse'],
            'STORE-SFA'  => ['email' => "sfax.cashier@{$domain}", 'pin' => '4444', 'name' => 'Caissier Sfax'],
        ];

        foreach ($shops as $shop) {
            $def = $definitions[$shop->code] ?? null;
            if ($def === null) {
                continue;
            }

            $user = User::firstOrCreate(
                ['email' => $def['email']],
                [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $this->tenant->id,
                    'name' => $def['name'],
                    'password' => Hash::make('password'),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                    'preferences' => [],
                ],
            );

            $this->recordIdentity($user, $this->tenant);

            UserCompanyMembership::firstOrCreate(
                ['user_id' => $user->id, 'company_id' => $company->id],
                [
                    'role' => MembershipRole::Cashier,
                    'allowed_location_ids' => [$shop->id],
                    'is_primary' => true,
                    'status' => MembershipStatus::Active,
                    'accepted_at' => now(),
                ],
            );

            if ($cashierRole) {
                $user->assignRole($cashierRole);
            }

            $user->update(['pos_pin' => Hash::make($def['pin'])]);
        }
    }
}
