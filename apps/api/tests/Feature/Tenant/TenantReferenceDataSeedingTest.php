<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantInitializationService;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T6 Phase 0b — deliverable 9: reference-data seeding at tenant init.
 *
 * Under database-per-tenant, the reference tables (countries, country_tax_rates,
 * ...) live in EACH tenant database and are NOT pre-populated. TenantInitialization
 * Service::seedTaxConfigurations() guards on `countries` existing, so without
 * self-seeding `countries` first, a freshly-provisioned tenant silently gets NO
 * tax configuration. This test reproduces the real scenario (countries NOT
 * pre-seeded) and asserts the init service seeds reference data itself.
 *
 * Note: unlike TenantInitializationTest, this test deliberately does NOT seed
 * CountriesSeeder in setUp.
 */
class TenantReferenceDataSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_seeds_reference_data_and_roles_in_a_fresh_tenant_database(): void
    {
        // Mirror a freshly-provisioned per-tenant database: NOTHING pre-seeded —
        // not countries, not roles/permissions.
        $this->assertSame(0, DB::table('countries')->count(), 'Precondition: countries must start empty.');
        $this->assertFalse(
            DB::table('roles')->where('name', 'admin')->exists(),
            'Precondition: the admin role must not pre-exist (fresh tenant DB).',
        );

        [$tenant, $company, $user] = $this->makeTenantCompanyUser('TN');

        app(TenantInitializationService::class)->initializeForNewRegistration($tenant, $company, $user);

        // Reference data self-seeded (so the tax-config countries guard passes).
        $this->assertGreaterThan(0, DB::table('countries')->count(), 'countries must be self-seeded.');
        $this->assertGreaterThan(
            0,
            DB::table('tax_configurations')->where('country_code', 'TN')->count(),
            'Tax configuration must be seeded once countries exist.',
        );

        // Country payment settings self-seeded (cash-rounding Phase 1 / spec §4.2).
        // This is the ONLY test that pins TenantInitializationService::seedReferenceData()
        // to CountryPaymentSettingsSeeder: on a fresh tenant DB the A2 migration's own
        // TN upsert is skipped (its country_code FK guard sees an empty `countries`),
        // so deleting the seeder call here silently leaves every new tenant with NO
        // tolerance ceiling and NO rounding denomination.
        $countrySettings = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull(
            $countrySettings,
            'seedReferenceData() must seed country_payment_settings after CountriesSeeder.',
        );
        $this->assertSame(0, bccomp((string) $countrySettings->max_payment_tolerance_amount, '0.1000', 4));
        $this->assertSame(0, bccomp((string) $countrySettings->cash_rounding_denomination, '0.0500', 4));
        $this->assertFalse((bool) $countrySettings->cash_rounding_enabled, 'Rounding must be provisioned OFF.');
        $this->assertFalse((bool) $countrySettings->pos_tolerance_enabled, 'POS tolerance must be provisioned OFF.');

        // Roles/permissions self-seeded, and the owner got the admin role.
        $this->assertTrue(DB::table('roles')->where('name', 'admin')->exists(), 'roles must be self-seeded.');
        setPermissionsTeamId($tenant->id);
        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertTrue($freshUser->hasRole('admin'), 'The registering user must hold the admin role.');
    }

    /**
     * N-9. A tenant provisioned through registration came up with ZERO `units`
     * (re-check 2026-08-25, day-one provisioning census: `units 0`). Units are
     * tenant-scoped reference data — `products.unit_id` points at them and
     * `units.decimal_places` drives every quantity the operator sees or types
     * (QuantityScale::formatForUnit) — so an empty table means no product can be
     * given a unit on day one.
     */
    public function test_initialize_seeds_the_base_unit_set_in_a_fresh_tenant_database(): void
    {
        $this->assertSame(0, DB::table('units')->count(), 'Precondition: a fresh tenant database has no units.');

        [$tenant, $company, $user] = $this->makeTenantCompanyUser('TN');

        app(TenantInitializationService::class)->initializeForNewRegistration($tenant, $company, $user);

        $this->assertGreaterThan(0, DB::table('unit_categories')->count(), 'Unit categories must be self-seeded.');

        $codes = DB::table('units')->pluck('code')->map(
            static fn (mixed $code): string => strtolower((string) $code)
        )->all();

        // The piece/unit the POS and every uncounted product falls back to, plus
        // the weight/volume/length/time bases the demo seeders assume exist.
        foreach (['pc', 'g', 'kg', 'ml', 'l', 'mm', 'cm', 'm', 'min', 'hr'] as $expected) {
            $this->assertContains($expected, $codes, "The base unit set must include '{$expected}'.");
        }

        // Every category must have its base unit resolved, or unit conversion has
        // nothing to convert through.
        $this->assertSame(
            0,
            DB::table('unit_categories')->whereNull('base_unit_id')->count(),
            'Every seeded unit category must point at its base unit.',
        );
    }

    /**
     * The guard is what makes this safe to add to a path that also runs in the
     * shared-DB compat mode, where units may already exist: UomSeeder uses bare
     * `create()` and would collide on `units.code` / `unit_categories.code`.
     */
    public function test_initialize_does_not_duplicate_units_when_they_already_exist(): void
    {
        [$tenantA, $companyA, $userA] = $this->makeTenantCompanyUser('TN');
        app(TenantInitializationService::class)->initializeForNewRegistration($tenantA, $companyA, $userA);

        $unitsAfterFirst = DB::table('units')->count();
        $categoriesAfterFirst = DB::table('unit_categories')->count();
        $this->assertGreaterThan(0, $unitsAfterFirst);

        [$tenantB, $companyB, $userB] = $this->makeTenantCompanyUser('FR');
        app(TenantInitializationService::class)->initializeForNewRegistration($tenantB, $companyB, $userB);

        $this->assertSame($unitsAfterFirst, DB::table('units')->count(), 'A second init must not re-seed units.');
        $this->assertSame($categoriesAfterFirst, DB::table('unit_categories')->count());
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: User}
     */
    private function makeTenantCompanyUser(string $countryCode): array
    {
        $tenant = Tenant::create([
            'name' => "Ref Tenant {$countryCode}",
            'slug' => 'ref-tenant-'.strtolower($countryCode).'-'.uniqid(),
            'status' => TenantStatus::Active,
            'country_code' => strtoupper($countryCode),
            'currency_code' => 'TND',
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Ref Company {$countryCode}",
            'country_code' => strtoupper($countryCode),
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => "Ref User {$countryCode}",
            'email' => strtolower($countryCode).'-ref@test.com',
            'password' => 'password',
            'status' => 'active',
        ]);

        return [$tenant, $company, $user];
    }
}
