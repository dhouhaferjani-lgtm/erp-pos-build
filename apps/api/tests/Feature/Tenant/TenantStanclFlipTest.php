<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Tests\TestCase;

/**
 * T6 Phase 0b — the row-level -> database-per-tenant flip, end to end.
 *
 * Proves the flip ACTUALLY creates one physical database per tenant (not a
 * shared schema), runs ONLY the tenant migrations inside it, leaves the central
 * database untouched, and keeps the `central` connection reachable from inside
 * tenant context (topology contract Pattern A).
 *
 * PG-only and deliberately does NOT use RefreshDatabase: PostgreSQL cannot run
 * `CREATE DATABASE` inside a transaction, which RefreshDatabase would open. The
 * test creates real databases and drops them in tearDown.
 */
class TenantStanclFlipTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The database-per-tenant flip is PostgreSQL-only.');
        }

        // Exercise real DB-per-tenant mode: this is what makes tenancy()->initialize()
        // actually swap the connection (TenancyServiceProvider gates the bootstrap
        // on this flag so the single-connection compat suite stays a no-op).
        config(['tenancy_resolver.db_per_tenant' => true]);

        // Central schema must exist. We cannot use RefreshDatabase (transaction),
        // so ensure the schema is present idempotently.
        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
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
                // best-effort: leaked test databases use unique random slugs.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_flip_creates_a_real_tenant_database_running_only_tenant_migrations(): void
    {
        // The flip target: database-per-tenant manager, not the schema manager.
        $this->assertSame(PostgreSQLDatabaseManager::class, config('tenancy.database.managers.pgsql'));

        $this->tenant = Tenant::factory()->create(['slug' => 'flip'.Str::lower(Str::random(10))]);
        $databaseName = $this->tenant->database()->getName();

        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));

        // 1. A NEW physical database was created on the server.
        $exists = DB::connection('central')->selectOne(
            'select 1 as ok from pg_database where datname = ?',
            [$databaseName],
        );
        $this->assertNotNull($exists, "The tenant database '{$databaseName}' should physically exist.");

        // 2 & 3. Query the tenant database directly (the 'tenant' connection Stancl
        //         configures on initialize): tenant migrations ran here; central
        //         tables did NOT. Asserting via the explicit connection avoids any
        //         ambiguity about whether the default-connection swap is reflected
        //         in the Schema facade.
        tenancy()->initialize($this->tenant);
        $tenantSchema = Schema::connection('tenant');

        $this->assertTrue($tenantSchema->hasTable('products'), 'Tenant migrations must run inside the tenant database.');
        $this->assertTrue($tenantSchema->hasTable('pos_receipts'), 'Tenant migrations must run inside the tenant database.');

        $this->assertFalse($tenantSchema->hasTable('tenants'), 'Central tables must NOT exist in the tenant database.');
        $this->assertFalse($tenantSchema->hasTable('plans'), 'Central tables must NOT exist in the tenant database.');
        $this->assertFalse($tenantSchema->hasTable('personal_access_tokens'), 'Central tables must NOT exist in the tenant database.');

        // 4. Pattern A: the `central` connection is reachable from tenant context
        //    and is NOT swapped to the tenant database.
        $centralRow = DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->first();
        $this->assertNotNull($centralRow, 'DB::connection(central) must reach the central database from tenant context.');

        tenancy()->end();

        // Central database untouched: still holds central tables after revert.
        $this->assertTrue(Schema::connection('central')->hasTable('tenants'));
    }
}
