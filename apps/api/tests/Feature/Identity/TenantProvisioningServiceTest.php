<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantProvisioningService;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T6 Phase 0b — deliverable 8: database-per-tenant registration provisioning.
 *
 * In DB mode, register cannot be one transaction: central rows (tenants, domains,
 * central_identities, the trial subscription) live in the central database while
 * the user/company/location live in a per-tenant database that must be created +
 * migrated first. This pins the orchestration: central rows committed to central,
 * a real tenant database created + seeded, the owner created INSIDE it, and a
 * working token — with no cross-database transaction.
 *
 * PG-only, DB-per-tenant mode, no RefreshDatabase (CREATE DATABASE cannot run in
 * a transaction). The tenant database is dropped in tearDown.
 */
class TenantProvisioningServiceTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-per-tenant provisioning is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
        // The trial plan lives in central; seed it there (no roles — the
        // provisioning self-seeds those into the new tenant database).
        Artisan::call('db:seed', ['--class' => PlansSeeder::class, '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        if ($this->tenant !== null) {
            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (\Throwable) {
                // best-effort
            }
            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('central_identities')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }
        parent::tearDown();
    }

    public function test_provisions_central_rows_and_a_real_tenant_database_with_the_owner_inside(): void
    {
        $validated = [
            'name' => 'Owner One',
            'email' => 'owner@acme.test',
            'password' => 'MyStr0ng!Pass',
            'company_name' => 'Acme SARL',
            'country_code' => 'TN',
            'currency' => 'TND',
            'vertical' => 'retail',
        ];

        $result = app(TenantProvisioningService::class)
            ->provisionForRegistration($validated, null, ['*'], null);

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertInstanceOf(Company::class, $result['company']);
        $this->assertNotSame('', $result['token'], 'A bearer token must be issued.');

        $this->tenant = Tenant::query()->where('slug', 'like', 'acme-sarl%')->firstOrFail();
        $databaseName = $this->tenant->database()->getName();

        // A real tenant database was created.
        $this->assertNotNull(
            DB::connection('central')->selectOne('select 1 as ok from pg_database where datname = ?', [$databaseName]),
            "Tenant database {$databaseName} must exist.",
        );

        // Central rows are in the central database.
        $this->assertTrue(DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->exists());
        $this->assertTrue(DB::connection('central')->table('central_identities')->where('email', 'owner@acme.test')->exists());
        $this->assertTrue(DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $this->tenant->id)->exists());

        // The owner + company + seeded data live INSIDE the tenant database.
        tenancy()->initialize($this->tenant);
        $this->assertTrue(DB::table('users')->where('email', 'owner@acme.test')->exists(), 'Owner must live in the tenant database.');
        $this->assertSame(1, DB::table('companies')->count());
        $this->assertGreaterThan(0, DB::table('tax_configurations')->where('country_code', 'TN')->count(), 'Reference data + tax config must be seeded in the tenant DB.');
        setPermissionsTeamId($this->tenant->id);
        $this->assertTrue($result['user']->fresh()?->hasRole('admin'), 'Owner must hold the admin role in the tenant DB.');

        // The tenant database does NOT contain central tables (checked while the
        // 'tenant' connection is still configured, i.e. before tenancy()->end()).
        $this->assertFalse(Schema::connection('tenant')->hasTable('tenants'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('plans'));

        // First-tenant launch, Lane D1 task 1/4 — a freshly REGISTERED tenant
        // (this real orchestration path, not a demo seeder) must already carry a
        // country_payment_settings row with both rounding switches DISABLED.
        // TenantInitializationService::seedReferenceData() has called
        // CountryPaymentSettingsSeeder unconditionally since before this lane —
        // this pins that the registration path was never the gap; only the four
        // demo seeders were (Lane D1 task 1 wired those separately).
        $settingsRow = DB::table('country_payment_settings')->where('country_code', 'TN')->first();
        $this->assertNotNull($settingsRow, 'A freshly registered tenant must have a country_payment_settings row for its country.');
        $this->assertFalse((bool) $settingsRow->cash_rounding_enabled);
        $this->assertFalse((bool) $settingsRow->pos_tolerance_enabled);

        // First-tenant launch, Lane D1 task 4 — REPORT (do not flip) finding:
        // the auto-created "Main Location" is provisioned with pos_enabled =
        // false. Confirmed via `git log -S pos_enabled` on this file: the value
        // has been `false` since the line was introduced in the original T6
        // deliverable-8 commit (2026-05-26) and has never been touched since —
        // and AuthController.php's shared-DB-compat registration path sets the
        // exact same `pos_enabled => false` for its own auto-created Main
        // Location. Both registration paths agree, so this reads as an
        // intentional default (an auto-provisioned "head office" placeholder
        // location is not, by default, a POS shop floor) rather than a bug —
        // this test pins the CURRENT behavior as a regression guard without
        // asserting it is the *correct* business decision, which is an owner
        // call (see E-8 in the gate sheet: the real tenant's terminal-creation
        // location must be decided/recorded before its first live transaction).
        $mainLocation = DB::table('locations')->where('code', 'MAIN')->first();
        $this->assertNotNull($mainLocation);
        $this->assertFalse((bool) $mainLocation->pos_enabled);

        tenancy()->end();
    }
}
