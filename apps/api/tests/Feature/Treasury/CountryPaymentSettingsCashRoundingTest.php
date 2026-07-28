<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryPaymentSettingsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration A2 + CountryPaymentSettingsSeeder.
 *
 * SHOULD be run on Postgres — the decimal(15,4) round-trip fidelity of
 * `cash_rounding_denomination` is the whole point of the string-cast test,
 * and only Postgres has a real fixed-scale NUMERIC type. The strict
 * 4-decimal format assertion is therefore driver-gated; every other
 * assertion holds on both drivers.
 *
 * `countries` is EMPTY after RefreshDatabase's migrate:fresh (the country
 * lookup is seeded, never migrated), so the migration's FK-guarded TN upsert
 * is skipped during the test bootstrap — exactly as it is skipped on a
 * freshly provisioned tenant. setUp() therefore seeds `countries` first and
 * then re-runs the migration `up()` to emulate the EXISTING-tenant path
 * (staging/prod, where `countries` is already populated when
 * `tenants:migrate` runs).
 */
final class CountryPaymentSettingsCashRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The country_payment_settings.country_code FK requires the lookup rows.
        $this->seed(CountriesSeeder::class);

        // Emulate `tenants:migrate` on an existing tenant whose `countries`
        // table is already populated: the A2 upsert branch actually runs.
        $this->runMigrationA2();
    }

    public function test_columns_exist_with_fail_closed_defaults(): void
    {
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'cash_rounding_enabled'));
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination'));
        $this->assertTrue(Schema::hasColumn('country_payment_settings', 'pos_tolerance_enabled'));

        // Fail-closed: a row inserted without touching the switches stays off.
        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();
        DB::table('country_payment_settings')->insert([
            'id' => '00000000-0000-4000-8000-00000000c0de',
            'country_code' => 'TN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $this->assertFalse((bool) $row->cash_rounding_enabled);
        $this->assertFalse((bool) $row->pos_tolerance_enabled);
        $this->assertNull($row->cash_rounding_denomination);
    }

    public function test_tn_upsert_state_1_no_row_is_created_by_migration(): void
    {
        // State 1 — the original seed insert never ran, so there is no TN row.
        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();

        $this->runMigrationA2();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->first();

        $this->assertNotNull($row, 'Migration A2 must upsert the TN row even when the original seed insert never ran.');
        $this->assertNotNull($row->id);
        $this->assertTrue($row->payment_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $row->payment_tolerance_percentage, '0.0050', 4));
        $this->assertSame(0, bccomp((string) $row->max_payment_tolerance_amount, '0.1000', 4));
        $this->assertFalse((bool) $row->pos_tolerance_enabled);
        $this->assertFalse((bool) $row->cash_rounding_enabled);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_tn_upsert_state_2_default_row_is_tightened(): void
    {
        // Simulate the pre-migration default-row state, then re-run the upsert.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'max_payment_tolerance_amount' => '0.5000',
            'cash_rounding_denomination' => null,
        ]);

        (new CountryPaymentSettingsSeeder)->run();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();
        $this->assertSame(0, bccomp((string) $row->max_payment_tolerance_amount, '0.1000', 4));
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.0500', 4));
    }

    public function test_tn_upsert_state_3_custom_operator_row_keeps_its_switches(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'pos_tolerance_enabled' => true,
            'cash_rounding_denomination' => '0.1000',
        ]);

        (new CountryPaymentSettingsSeeder)->run();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();
        $this->assertTrue((bool) $row->cash_rounding_enabled, 'Seeder must not stomp an operator-enabled switch.');
        $this->assertTrue((bool) $row->pos_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.1000', 4));
    }

    public function test_migration_rerun_keeps_operator_switches_and_denomination(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'pos_tolerance_enabled' => true,
            'cash_rounding_denomination' => '0.1000',
        ]);

        // tenants:migrate is re-run (auto-deploy) — the migration must be idempotent
        // AND must not stomp what the operator configured.
        $this->runMigrationA2();

        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();
        $this->assertTrue((bool) $row->cash_rounding_enabled);
        $this->assertTrue((bool) $row->pos_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $row->cash_rounding_denomination, '0.1000', 4));
    }

    public function test_seeder_is_idempotent_and_seeds_fr_without_a_denomination(): void
    {
        (new CountryPaymentSettingsSeeder)->run();
        (new CountryPaymentSettingsSeeder)->run();

        $this->assertSame(1, DB::table('country_payment_settings')->where('country_code', 'TN')->count());
        $this->assertSame(1, DB::table('country_payment_settings')->where('country_code', 'FR')->count());

        $fr = CountryPaymentSettings::query()->where('country_code', 'FR')->firstOrFail();
        $this->assertTrue($fr->payment_tolerance_enabled);
        $this->assertSame(0, bccomp((string) $fr->max_payment_tolerance_amount, '0.5000', 4));
        $this->assertNull($fr->cash_rounding_denomination, 'FR has no sub-unit rounding — denomination stays NULL.');
        $this->assertFalse((bool) $fr->cash_rounding_enabled);
        $this->assertFalse((bool) $fr->pos_tolerance_enabled);
    }

    public function test_denomination_survives_decimal_round_trip_as_string(): void
    {
        $row = CountryPaymentSettings::query()->where('country_code', 'TN')->firstOrFail();

        $this->assertIsString($row->cash_rounding_denomination);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Fixed-scale NUMERIC round-trip is only meaningful on Postgres.');
        }

        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', (string) $row->cash_rounding_denomination);
    }

    public function test_tn_row_tightens_the_live_b2b_tolerance_ceiling(): void
    {
        $tenant = Tenant::create([
            'name' => 'CPS Tenant',
            'slug' => 'cps-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'CPS Shop',
            'legal_name' => 'CPS Shop SARL',
            'tax_id' => 'TAX-CPS-1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        $service = app(PaymentToleranceService::class);
        $settings = $service->getToleranceSettings($company->id);

        $this->assertSame('0.1000', $settings['max_amount']);
        $this->assertSame('country', $settings['source']);
    }

    /**
     * Re-run migration A2's `up()` against the current connection.
     *
     * The migration is self-guarding (hasTable / hasColumn / FK presence), so
     * calling it again after the RefreshDatabase bootstrap is safe and is the
     * only way to exercise the TN upsert branch — during migrate:fresh the
     * `countries` lookup is still empty.
     */
    private function runMigrationA2(): void
    {
        $migration = require database_path(
            'migrations/tenant/2026_07_28_100100_add_cash_rounding_to_country_payment_settings.php'
        );

        $this->assertInstanceOf(Migration::class, $migration);

        $migration->up();
    }
}
