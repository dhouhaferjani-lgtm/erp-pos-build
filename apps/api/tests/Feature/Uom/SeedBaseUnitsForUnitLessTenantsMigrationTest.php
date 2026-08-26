<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\UomSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * N-9 backfill. The registration path is fixed at the source
 * (TenantInitializationService::seedReferenceData); this migration is what
 * reaches the tenants that were already provisioned with `units 0`.
 */
final class SeedBaseUnitsForUnitLessTenantsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The migration backfills a PROVISIONED tenant only — a database with no
        // company is a migration target that has not been provisioned yet (and
        // is where every fresh test database sits), so it is deliberately left
        // to TenantInitializationService.
        $this->provisionedTenant();
    }

    public function test_it_skips_a_database_that_has_not_been_provisioned_yet(): void
    {
        DB::table('companies')->delete();

        $this->runMigration();

        $this->assertSame(
            0,
            DB::table('units')->count(),
            'A pre-provisioning migration target must be left to the provisioning path.',
        );
    }

    public function test_it_seeds_the_base_unit_set_on_a_unit_less_tenant(): void
    {
        $this->assertSame(0, DB::table('units')->count(), 'Precondition: the tenant has no units.');

        $this->runMigration();

        $codes = DB::table('units')->pluck('code')->map(
            static fn (mixed $code): string => strtolower((string) $code)
        )->all();

        foreach (['pc', 'g', 'kg', 'ml', 'l', 'mm', 'cm', 'm', 'min', 'hr'] as $expected) {
            $this->assertContains($expected, $codes, "The backfill must include '{$expected}'.");
        }

        $this->assertSame(
            0,
            DB::table('unit_categories')->whereNull('base_unit_id')->count(),
            'Every seeded category must point at its base unit.',
        );
    }

    public function test_re_running_it_is_a_no_op(): void
    {
        $this->runMigration();

        $units = DB::table('units')->count();
        $categories = DB::table('unit_categories')->count();
        $this->assertGreaterThan(0, $units);

        $this->runMigration();

        $this->assertSame($units, DB::table('units')->count(), 'A second run must not duplicate units.');
        $this->assertSame($categories, DB::table('unit_categories')->count());
    }

    /**
     * A tenant that already has units — a demo tenant, or one that customised
     * its own — must be left completely alone, because UomSeeder writes with
     * bare create() and would collide on `unit_categories.code`.
     */
    public function test_it_leaves_a_tenant_that_already_has_units_untouched(): void
    {
        (new UomSeeder)->run();
        DB::table('units')->where('code', 'oz')->delete();

        $before = DB::table('units')->count();

        $this->runMigration();

        $this->assertSame($before, DB::table('units')->count());
        $this->assertSame(
            0,
            DB::table('units')->where('code', 'oz')->count(),
            'The guard is all-or-nothing: it must not top up a partially-customised set.',
        );
    }

    private function provisionedTenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Unit Backfill Tenant',
            'slug' => 'unit-backfill-'.uniqid(),
            'status' => TenantStatus::Active,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unit Backfill Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }

    private function runMigration(): void
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../../../database/migrations/tenant/2026_08_26_100000_seed_base_units_for_unit_less_tenants.php';
        $migration->up();
    }
}
