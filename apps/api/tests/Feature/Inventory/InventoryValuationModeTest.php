<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\EffectiveValuationMode;
use App\Modules\Inventory\Application\Services\InventoryValuationModeResolver;
use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;
use App\Modules\Inventory\Domain\Exceptions\UnsupportedValuationModeException;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\CompanyFactory;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryInventorySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA Wave 3 · sub-wave 3B · **T7 + T8 + T9 — the valuation parameter**.
 *
 * Wave 3 books COGS at stock exit, which is only correct under PERPETUAL
 * valuation. Under periodic valuation the charge comes from a period-end count
 * and every exit-seam entry would be a mis-statement. So the mode has to be an
 * explicit, resolvable, refusable parameter BEFORE the seam is built — that is
 * the whole of sub-wave 3B.
 *
 * D-14's shape is three layers, and all three are asserted here:
 *
 *  1. the enum names `Periodic` and the DB CHECK admits it, so enabling it later
 *     needs no DDL on a live tenant database;
 *  2. the settings WRITE boundary refuses it with a 422;
 *  3. the RESOLVER refuses it, so a company stamped out-of-band (a direct SQL
 *     UPDATE, a restored backup) still cannot silently produce wrong entries.
 */
final class InventoryValuationModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        /** @var Company $company */
        $company = CompanyFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
        ]);
        $this->company = $company;

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    // =================================================================
    // T7 — the enum, the column, the CHECK
    // =================================================================

    public function test_the_check_constraint_rejects_a_third_value(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('CHECK constraints are only enforced on PostgreSQL here.');
        }

        $this->expectException(QueryException::class);

        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['inventory_valuation_mode' => 'lifo']);
    }

    public function test_the_check_constraint_admits_periodic_so_enabling_it_needs_no_ddl(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('CHECK constraints are only enforced on PostgreSQL here.');
        }

        // The whole point of D-14 layer 1: the SCHEMA is ready, the machinery is
        // not. If this ever starts failing, enabling periodic becomes a DDL
        // change on every live tenant database.
        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['inventory_valuation_mode' => InventoryValuationMode::Periodic->value]);

        self::assertSame(
            'periodic',
            DB::table('companies')->where('id', $this->company->id)->value('inventory_valuation_mode'),
        );
    }

    public function test_the_column_round_trips_through_the_enum_cast(): void
    {
        $this->company->update(['inventory_valuation_mode' => InventoryValuationMode::Perpetual]);

        /** @var Company $fresh */
        $fresh = $this->company->fresh();
        self::assertSame(InventoryValuationMode::Perpetual, $fresh->inventory_valuation_mode);

        $fresh->update(['inventory_valuation_mode' => null]);
        self::assertNull($fresh->fresh()?->inventory_valuation_mode);
    }

    public function test_only_perpetual_is_supported(): void
    {
        self::assertTrue(InventoryValuationMode::Perpetual->isSupported());
        self::assertFalse(InventoryValuationMode::Periodic->isSupported());
    }

    // =================================================================
    // T8 — country rows, seeder idempotence, unknown country
    // =================================================================

    public function test_the_seeder_creates_a_row_for_every_pinned_country_and_is_idempotent(): void
    {
        $this->seed(CountriesSeeder::class);

        (new CountryInventorySettingsSeeder)->run();
        $afterFirstRun = DB::table('country_inventory_settings')->orderBy('country_code')->get();

        (new CountryInventorySettingsSeeder)->run();
        $afterSecondRun = DB::table('country_inventory_settings')->orderBy('country_code')->get();

        self::assertCount(2, $afterFirstRun, 'TN and FR are the pinned countries');
        self::assertSame(
            ['FR', 'TN'],
            $afterFirstRun->pluck('country_code')->all(),
        );
        foreach ($afterFirstRun as $row) {
            self::assertSame('perpetual', $row->inventory_valuation_mode);
        }

        self::assertSame(
            $afterFirstRun->pluck('id')->all(),
            $afterSecondRun->pluck('id')->all(),
            'a second run must update in place, never duplicate',
        );
        self::assertCount(2, $afterSecondRun);
    }

    public function test_the_seeder_is_a_no_op_when_the_countries_lookup_is_empty(): void
    {
        // Fail-closed guard: the seeder can run before CountriesSeeder (a tenant
        // database mid-provisioning). It must degrade to nothing rather than
        // violate the country_code FK.
        DB::table('country_inventory_settings')->delete();
        DB::table('countries')->delete();

        (new CountryInventorySettingsSeeder)->run();

        self::assertSame(0, DB::table('country_inventory_settings')->count());
    }

    // =================================================================
    // T9 — the resolution chain and the two refusals
    // =================================================================

    public function test_the_country_row_is_used_when_the_company_column_is_null(): void
    {
        $this->seed(CountriesSeeder::class);
        (new CountryInventorySettingsSeeder)->run();

        $this->company->update(['inventory_valuation_mode' => null]);

        $effective = $this->app->make(InventoryValuationModeResolver::class)->resolve($this->company->id);

        self::assertSame(InventoryValuationMode::Perpetual, $effective->mode);
        self::assertSame(EffectiveValuationMode::SOURCE_COUNTRY, $effective->source);
    }

    public function test_the_company_override_wins_over_the_country_row(): void
    {
        $this->seed(CountriesSeeder::class);
        (new CountryInventorySettingsSeeder)->run();

        $this->company->update(['inventory_valuation_mode' => InventoryValuationMode::Perpetual]);

        $effective = $this->app->make(InventoryValuationModeResolver::class)->resolve($this->company->id);

        self::assertSame(EffectiveValuationMode::SOURCE_COMPANY, $effective->source);
    }

    public function test_an_unknown_country_falls_to_the_system_default_and_says_so(): void
    {
        // CountryInventoryDefaults returns null for anything unpinned and no
        // caller may invent a mode — the fallback is explicit and visible in
        // `source`, not a silent guess.
        DB::table('country_inventory_settings')->delete();
        DB::table('companies')->where('id', $this->company->id)->update([
            'country_code' => 'ZZ',
            'inventory_valuation_mode' => null,
        ]);

        $effective = $this->app->make(InventoryValuationModeResolver::class)->resolve($this->company->id);

        self::assertSame(InventoryValuationMode::Perpetual, $effective->mode);
        self::assertSame(EffectiveValuationMode::SOURCE_SYSTEM, $effective->source);
    }

    public function test_tn_and_fr_both_resolve_to_perpetual_with_a_null_company_column(): void
    {
        $this->seed(CountriesSeeder::class);
        (new CountryInventorySettingsSeeder)->run();

        foreach (['TN', 'FR'] as $countryCode) {
            /** @var Company $company */
            $company = CompanyFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'country_code' => $countryCode,
                'inventory_valuation_mode' => null,
            ]);

            $effective = $this->app->make(InventoryValuationModeResolver::class)->resolve($company->id);

            self::assertSame(InventoryValuationMode::Perpetual, $effective->mode, $countryCode);
            self::assertSame(EffectiveValuationMode::SOURCE_COUNTRY, $effective->source, $countryCode);
        }
    }

    public function test_the_resolver_refuses_a_company_stamped_periodic_out_of_band(): void
    {
        // Layer 3. The 422 guards the API; this guards everything else — a
        // direct SQL UPDATE, a restored backup, a future admin tool.
        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['inventory_valuation_mode' => InventoryValuationMode::Periodic->value]);

        $resolver = $this->app->make(InventoryValuationModeResolver::class);

        // resolve() REPORTS it…
        $effective = $resolver->resolve($this->company->id);
        self::assertSame(InventoryValuationMode::Periodic, $effective->mode);
        self::assertFalse($effective->isSupported());

        // …requirePerpetual() REFUSES it.
        try {
            $resolver->requirePerpetual($this->company->id);
            self::fail('requirePerpetual() must throw for a periodic company');
        } catch (UnsupportedValuationModeException $exception) {
            self::assertSame($this->company->id, $exception->companyId);
            self::assertSame(InventoryValuationMode::Periodic, $exception->mode);
        }
    }

    public function test_require_perpetual_passes_for_a_perpetual_company(): void
    {
        $this->company->update(['inventory_valuation_mode' => InventoryValuationMode::Perpetual]);

        $resolver = $this->app->make(InventoryValuationModeResolver::class);
        $resolver->requirePerpetual($this->company->id);

        // The assertion is that the line above did not throw; assert something
        // observable rather than assertTrue(true), which PHPStan (correctly)
        // rejects as always-true.
        self::assertTrue($resolver->resolve($this->company->id)->isSupported());
    }

    // =================================================================
    // T9 — layer 2: the settings write boundary
    // =================================================================

    public function test_the_settings_endpoint_refuses_periodic_with_a_422(): void
    {
        $user = $this->authenticatedSettingsUser();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'inventory_valuation_mode' => InventoryValuationMode::Periodic->value,
            ]);

        $response->assertStatus(422);
        self::assertNull(
            $this->company->fresh()?->inventory_valuation_mode,
            'a refused request must not have written anything',
        );
    }

    public function test_the_settings_endpoint_accepts_perpetual(): void
    {
        $user = $this->authenticatedSettingsUser();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', [
                'inventory_valuation_mode' => InventoryValuationMode::Perpetual->value,
            ])
            ->assertOk();

        self::assertSame(
            InventoryValuationMode::Perpetual,
            $this->company->fresh()?->inventory_valuation_mode,
        );
    }

    private function authenticatedSettingsUser(): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->givePermissionTo('settings.update');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        return $user;
    }
}
