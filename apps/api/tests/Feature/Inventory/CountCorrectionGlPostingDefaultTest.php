<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\EffectiveCountCorrectionGlPosting;
use App\Modules\Inventory\Application\Services\CountCorrectionGlPostingResolver;
use App\Modules\Inventory\Domain\CountryInventoryDefaults;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\CountryInventorySettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lane P-1 (owner ruling 2026-08-25) — count-correction GL posting is SEEDED ON.
 *
 * The owner ruled that `count_correction_gl_posting_enabled` ships **true**,
 * per country, tenant-editable, with the expert-comptable reviewing the Option A
 * account choice (6586 / 7586) later at onboarding. That supersedes the
 * OQ-12/H-5 deploy-time blocker recorded in
 * `docs/handoff/reviews/wave3-3c-3d/ORCHESTRATOR-RULING-2026-08-19-m5-oq12-gate.md`.
 *
 * This class pins the SETTING — the seeded per-country default, the resolution
 * chain, the tenant override, and the backfill migration's predicate. The GL
 * SHAPE (Dr 6586 / Cr 37 at the shipped default) is pinned in the `[PG]` sibling
 * `CountCorrectionGlPostingTest`, which owns the non-transactional root-frame
 * fixture the posting buffer needs.
 *
 * ## What "explicitly set" means here
 *
 * `companies.count_correction_gl_posting_enabled` is **nullable and
 * undefaulted**: NULL means "inherit", a non-NULL boolean means an operator
 * decided. That is the same representation `inventory_valuation_mode` already
 * uses (T9), so the migration's predicate needs no heuristic — it never writes
 * that column at all.
 */
final class CountCorrectionGlPostingDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Count GL Default Tenant',
            'slug' => 'countgldef-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Count GL Default Company',
            'legal_name' => 'Count GL Default Company LLC',
            'tax_id' => 'CGD-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    // --------------------------------------------------- the seeded default

    /**
     * A freshly provisioned tenant runs the same seeder the provisioning path
     * runs (`TenantInitializationService::seedReferenceData()`), and every
     * seeded country comes out ON.
     */
    public function test_fresh_provisioning_seeds_every_country_with_posting_enabled(): void
    {
        $this->provisionReferenceData();

        $seeded = DB::table('country_inventory_settings')
            ->pluck('count_correction_gl_posting_enabled', 'country_code')
            ->all();

        self::assertNotSame([], $seeded, 'The provisioning seeder must write a row per supported country.');

        foreach (CountryInventoryDefaults::allCountCorrectionGlPosting() as $countryCode => $expected) {
            self::assertArrayHasKey($countryCode, $seeded);
            self::assertSame($expected, (bool) $seeded[$countryCode], $countryCode.' must be seeded ON.');
        }

        self::assertTrue((bool) $seeded['TN'], 'Tunisia — tenant #1 — must be seeded ON.');
    }

    /**
     * The seeder PINS the country row, exactly as it pins the valuation mode:
     * the jurisdiction default is not operator state.
     */
    public function test_the_seeder_repins_a_country_row_that_drifted_off(): void
    {
        $this->provisionReferenceData();

        DB::table('country_inventory_settings')
            ->where('country_code', 'TN')
            ->update(['count_correction_gl_posting_enabled' => false]);

        (new CountryInventorySettingsSeeder)->run();

        self::assertTrue((bool) DB::table('country_inventory_settings')
            ->where('country_code', 'TN')
            ->value('count_correction_gl_posting_enabled'));
    }

    // ------------------------------------------------------ the resolution chain

    public function test_a_provisioned_company_resolves_enabled_from_its_country(): void
    {
        $this->provisionReferenceData();

        $effective = $this->resolver()->resolve($this->company->id);

        self::assertTrue($effective->enabled);
        self::assertSame(EffectiveCountCorrectionGlPosting::SOURCE_COUNTRY, $effective->source);
    }

    /**
     * No country row at all (an unsupported jurisdiction, or a tenant whose
     * reference data never ran) still posts: the SYSTEM default is ON, which is
     * what the owner ruling means by "seeded true".
     */
    public function test_a_company_with_no_country_row_falls_back_to_the_system_default(): void
    {
        DB::table('country_inventory_settings')->delete();

        $effective = $this->resolver()->resolve($this->company->id);

        self::assertTrue($effective->enabled);
        self::assertSame(EffectiveCountCorrectionGlPosting::SOURCE_SYSTEM, $effective->source);
    }

    public function test_an_explicit_company_override_beats_the_country_row(): void
    {
        $this->provisionReferenceData();

        $this->company->update(['count_correction_gl_posting_enabled' => false]);

        $effective = $this->resolver()->resolve($this->company->id);

        self::assertFalse($effective->enabled);
        self::assertSame(EffectiveCountCorrectionGlPosting::SOURCE_COMPANY, $effective->source);
    }

    public function test_clearing_the_override_re_inherits_the_country_default(): void
    {
        $this->provisionReferenceData();

        $this->company->update(['count_correction_gl_posting_enabled' => false]);
        $this->company->update(['count_correction_gl_posting_enabled' => null]);

        $effective = $this->resolver()->resolve($this->company->id);

        self::assertTrue($effective->enabled);
        self::assertSame(EffectiveCountCorrectionGlPosting::SOURCE_COUNTRY, $effective->source);
    }

    // ------------------------------------------------------------ the migration

    /**
     * The backfill raises a country row that still carries the pre-ruling
     * default, and is safe to run twice.
     */
    public function test_the_migration_raises_a_country_row_that_still_holds_the_seeded_default(): void
    {
        $this->provisionReferenceData();

        DB::table('country_inventory_settings')
            ->where('country_code', 'TN')
            ->update(['count_correction_gl_posting_enabled' => false]);

        $this->runMigration();

        self::assertTrue((bool) DB::table('country_inventory_settings')
            ->where('country_code', 'TN')
            ->value('count_correction_gl_posting_enabled'));

        $this->runMigration();

        self::assertTrue((bool) DB::table('country_inventory_settings')
            ->where('country_code', 'TN')
            ->value('count_correction_gl_posting_enabled'));
    }

    /**
     * THE guard the brief asks for: a tenant that turned posting OFF on purpose
     * still has it OFF after the migration runs — twice.
     */
    public function test_a_company_that_explicitly_set_false_stays_false_across_the_migration(): void
    {
        $this->provisionReferenceData();

        $this->company->update(['count_correction_gl_posting_enabled' => false]);

        $this->runMigration();
        $this->runMigration();

        self::assertFalse((bool) DB::table('companies')
            ->where('id', $this->company->id)
            ->value('count_correction_gl_posting_enabled'));

        self::assertFalse($this->resolver()->resolve($this->company->id)->enabled);
    }

    /**
     * And a company that never touched the setting is left NULL — the migration
     * does not stamp an override on to it, which would freeze that tenant out of
     * any future country ruling.
     */
    public function test_the_migration_never_stamps_an_override_on_an_untouched_company(): void
    {
        $this->provisionReferenceData();

        $this->runMigration();

        self::assertNull(DB::table('companies')
            ->where('id', $this->company->id)
            ->value('count_correction_gl_posting_enabled'));
    }

    // ------------------------------------------------- the settings surface

    /**
     * `GET /settings/company` reports the RESOLVED answer, where it came from,
     * and the tenant's own override — three separate facts, because "off"
     * because I said so and "off" because my jurisdiction says so are different
     * support conversations.
     */
    public function test_the_settings_endpoint_reports_the_resolved_answer_its_source_and_the_override(): void
    {
        $this->provisionReferenceData();
        $user = $this->authenticatedSettingsUser();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/settings/company')
            ->assertOk()
            ->assertJsonPath('data.count_correction_gl_posting_enabled', true)
            ->assertJsonPath('data.count_correction_gl_posting_source', EffectiveCountCorrectionGlPosting::SOURCE_COUNTRY)
            ->assertJsonPath('data.count_correction_gl_posting_override', null);
    }

    public function test_the_settings_endpoint_writes_and_clears_the_tenant_override(): void
    {
        $this->provisionReferenceData();
        $user = $this->authenticatedSettingsUser();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', ['count_correction_gl_posting_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.count_correction_gl_posting_enabled', false)
            ->assertJsonPath('data.count_correction_gl_posting_source', EffectiveCountCorrectionGlPosting::SOURCE_COMPANY);

        self::assertFalse($this->company->fresh()?->count_correction_gl_posting_enabled);

        // `null` CLEARS the override — the only way back to "whatever my
        // jurisdiction says", which is why the rule is nullable.
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', ['count_correction_gl_posting_enabled' => null])
            ->assertOk()
            ->assertJsonPath('data.count_correction_gl_posting_enabled', true)
            ->assertJsonPath('data.count_correction_gl_posting_source', EffectiveCountCorrectionGlPosting::SOURCE_COUNTRY);

        self::assertNull($this->company->fresh()?->count_correction_gl_posting_enabled);
    }

    public function test_the_settings_endpoint_refuses_a_non_boolean_with_a_422(): void
    {
        $this->provisionReferenceData();
        $user = $this->authenticatedSettingsUser();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson('/api/v1/settings/company', ['count_correction_gl_posting_enabled' => 'maybe'])
            ->assertStatus(422);

        self::assertNull($this->company->fresh()?->count_correction_gl_posting_enabled);
    }

    // ---------------------------------------------------------------- helpers

    private function authenticatedSettingsUser(): User
    {
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->givePermissionTo('settings.update');
        $user->givePermissionTo('settings.view');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        return $user;
    }

    private function provisionReferenceData(): void
    {
        (new CountriesSeeder)->run();
        (new CountryInventorySettingsSeeder)->run();
    }

    private function resolver(): CountCorrectionGlPostingResolver
    {
        return $this->app->make(CountCorrectionGlPostingResolver::class);
    }

    private function runMigration(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/tenant/2026_08_25_140000_seed_count_correction_gl_posting_default.php';
        $migration->up();
    }
}
