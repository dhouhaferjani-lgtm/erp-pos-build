<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Console\TenantScopedCommand;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Throwable;

/**
 * Give a tenant a per-tenant database that actually EXISTS.
 *
 * Needed by any test that drives {@see TenantScopedCommand::forEachTenant()}
 * with `tenancy_resolver.db_per_tenant = true`: since the 2026-08-05 review
 * fix, that loop probes `databaseExists()` before initializing (mirroring
 * {@see TenancyResolver}) and SKIPS a
 * tenant whose database is absent — the "directory row with no database" case.
 * A test tenant created by `Tenant::create()` has no database at all, so
 * without this helper such a tenant is (correctly) skipped and the loop never
 * runs.
 *
 * Under the suite's SQLite driver a tenant database is a file at
 * `database_path(<database name>)` — the exact thing Stancl's
 * SQLiteDatabaseManager creates and probes. The file is registered for deletion
 * when the application is torn down, so nothing leaks into `database/`.
 */
trait ProvisionsTenantDatabases
{
    protected function provisionTenantDatabase(Tenant $tenant): Tenant
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Bus::dispatchSync(new CreateDatabase($tenant));
            $this->beforeApplicationDestroyed(static function () use ($tenant): void {
                try {
                    if (tenancy()->initialized) {
                        tenancy()->end();
                    }
                    DB::purge('tenant');
                    $tenant->database()->manager()->deleteDatabase($tenant);
                } catch (Throwable) {
                    // Best-effort cleanup must not hide the original assertion failure.
                }
            });

            return $tenant;
        }

        $path = database_path($tenant->getDatabaseName());

        touch($path);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $tenant;
    }

    /**
     * Provision a tenant database that also carries the full application
     * SCHEMA, so a command run under `db_per_tenant = true` can actually read
     * and write tenant tables inside it.
     *
     * {@see self::provisionTenantDatabase()} only makes the database EXIST —
     * enough to get past `forEachTenant()`'s probe, but an empty file cannot
     * prove anything about WHICH database a query landed on. That is exactly
     * the blind spot the 2026-08-05 wave-2 reviews called out (fiscal R4 /
     * tenancy R5): every wave test ran in single-schema compat mode, so a
     * collaborator pinned to the CENTRAL connection stayed invisible.
     *
     * Rather than replay 500+ tenant migrations per test, the schema is cloned
     * from the central connection's `sqlite_master` — the compat suite migrates
     * every table into that one in-memory database, so its DDL is by
     * construction identical to (and never drifts from) the tenant schema.
     * DDL only: no rows are copied, which is what makes "central has the row,
     * the tenant database does not" a usable probe.
     */
    protected function provisionTenantDatabaseWithSchema(Tenant $tenant): Tenant
    {
        $this->provisionTenantDatabase($tenant);

        if (DB::connection()->getDriverName() === 'pgsql') {
            Bus::dispatchSync(new MigrateDatabase($tenant));

            return $tenant;
        }

        /** @var list<object{sql: string}> $objects */
        $objects = DB::connection()->select(
            "select sql from sqlite_master where sql is not null and name not like 'sqlite_%' order by case type when 'table' then 0 else 1 end",
        );

        $this->withinTenantDatabase($tenant, static function () use ($objects): void {
            DB::statement('PRAGMA foreign_keys = OFF');

            foreach ($objects as $object) {
                try {
                    DB::statement($object->sql);
                } catch (Throwable) {
                    // An object the tenant database already has (sqlite creates
                    // a few implicitly). Cloning is best-effort per object; a
                    // table the test actually needs surfaces as a loud
                    // "no such table" in the test itself.
                }
            }
        });

        return $tenant;
    }

    /**
     * Run `$fn` with tenancy bound to `$tenant`, always ending the binding.
     *
     * Tests build their fixtures through this so the rows land in the TENANT
     * database rather than in central — the whole point of a db-per-tenant leg.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $fn
     * @return TReturn
     */
    protected function withinTenantDatabase(Tenant $tenant, callable $fn): mixed
    {
        $wasInitialized = tenancy()->initialized;

        tenancy()->initialize($tenant);

        try {
            return $fn($tenant);
        } finally {
            if (! $wasInitialized && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
