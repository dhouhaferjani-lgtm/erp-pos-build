<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\TenantDeprovisioningService;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — tenant database lifecycle teardown (DB-per-tenant mode).
 *
 * The provisioning counterpart: a deleted/archived tenant's PHYSICAL per-tenant
 * database must be dropped and its central directory rows removed; a suspended
 * tenant's database must SURVIVE (suspend is reversible).
 *
 * PG-only, DB-per-tenant mode, NO RefreshDatabase (DROP/CREATE DATABASE cannot
 * run inside a transaction). Any tenant databases created here are dropped in
 * tearDown so a failed assertion never leaks a physical database.
 */
class TenantDeprovisioningTest extends TestCase
{
    /** @var array<int, Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Tenant database teardown is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            try {
                DB::purge('tenant');
                if ($tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
                    $tenant->database()->manager()->deleteDatabase($tenant);
                }
            } catch (\Throwable) {
                // best-effort: leaked databases use unique random slugs.
            }

            DB::connection('central')->table('central_identities')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_deprovision_drops_the_physical_database_and_removes_central_rows(): void
    {
        $tenant = $this->provisionTenant('drop');
        $databaseName = $tenant->database()->getName();

        // Seed the central directory rows that the teardown must clean up.
        $this->seedCentralDirectory($tenant);

        // Sanity: the physical database and central rows all exist beforehand.
        $this->assertTrue(
            $this->physicalDatabaseExists($databaseName),
            "The tenant database '{$databaseName}' must physically exist before teardown.",
        );

        $service = $this->makeService();
        $service->deprovision($tenant);

        // The physical database is gone.
        $this->assertFalse(
            $this->physicalDatabaseExists($databaseName),
            "The tenant database '{$databaseName}' must be physically dropped after deprovision.",
        );

        // Every central directory row for this tenant is gone.
        $this->assertCentralRowsGone($tenant->id);
    }

    public function test_deprovision_is_idempotent_when_the_database_was_never_provisioned(): void
    {
        // A tenant row whose physical database was never created (e.g. provisioning
        // failed mid-flight, or a pre-warm row that was never claimed/migrated).
        $tenant = Tenant::factory()->create(['slug' => 'noprov'.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        $this->assertFalse(
            $this->physicalDatabaseExists($tenant->database()->getName()),
            'Precondition: this tenant must have no physical database.',
        );

        $this->seedCentralDirectory($tenant);

        $service = $this->makeService();

        // Must NOT throw even though there is no database to drop.
        $service->deprovision($tenant);

        $this->assertCentralRowsGone($tenant->id);
    }

    public function test_deprovision_called_twice_does_not_error(): void
    {
        $tenant = $this->provisionTenant('twice');
        $this->seedCentralDirectory($tenant);

        $service = $this->makeService();
        $service->deprovision($tenant);
        // Second call on an already-gone tenant must be a safe no-op.
        $service->deprovision($tenant);

        $this->assertCentralRowsGone($tenant->id);
    }

    public function test_suspend_keeps_the_physical_database_and_only_changes_status(): void
    {
        $tenant = $this->provisionTenant('suspend');
        $databaseName = $tenant->database()->getName();
        $this->seedCentralDirectory($tenant);

        $service = $this->makeService();
        $service->suspend($tenant);

        // The database SURVIVES — suspend is reversible.
        $this->assertTrue(
            $this->physicalDatabaseExists($databaseName),
            'Suspend must NOT drop the tenant database.',
        );

        // Status flipped to Suspended; central rows untouched.
        $fresh = Tenant::on('central')->find($tenant->id);
        $this->assertNotNull($fresh);
        $this->assertSame(TenantStatus::Suspended, $fresh->status);

        $this->assertSame(
            1,
            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->count(),
            'Suspend must leave the central directory rows intact.',
        );
    }

    private function makeService(): TenantDeprovisioningService
    {
        return $this->app->make(TenantDeprovisioningService::class);
    }

    private function provisionTenant(string $slugPrefix): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slugPrefix.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    private function seedCentralDirectory(Tenant $tenant): void
    {
        DB::connection('central')->table('domains')->insert([
            'id' => (string) Str::uuid(),
            'domain' => $tenant->slug.'.synerivia.tn',
            'tenant_id' => $tenant->id,
            'is_primary' => true,
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('central')->table('central_identities')->insert([
            'id' => (string) Str::uuid(),
            'email' => 'owner+'.$tenant->slug.'@example.test',
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::connection('central')->table('plans')->value('id');
        if (is_string($planId)) {
            DB::connection('central')->table('tenant_subscriptions')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'status' => 'trial',
                'billing_cycle' => 'monthly',
                'currency' => 'TND',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function physicalDatabaseExists(string $databaseName): bool
    {
        return DB::connection('central')->selectOne(
            'select 1 as ok from pg_database where datname = ?',
            [$databaseName],
        ) !== null;
    }

    private function assertCentralRowsGone(string $tenantId): void
    {
        $this->assertNull(
            DB::connection('central')->table('tenants')->where('id', $tenantId)->first(),
            'The central tenants row must be removed.',
        );
        $this->assertSame(0, DB::connection('central')->table('domains')->where('tenant_id', $tenantId)->count(), 'domains rows must be removed.');
        $this->assertSame(0, DB::connection('central')->table('central_identities')->where('tenant_id', $tenantId)->count(), 'central_identities rows must be removed.');
        $this->assertSame(0, DB::connection('central')->table('tenant_subscriptions')->where('tenant_id', $tenantId)->count(), 'tenant_subscriptions rows must be removed.');
    }
}
