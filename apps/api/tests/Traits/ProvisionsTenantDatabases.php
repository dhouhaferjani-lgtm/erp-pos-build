<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Console\TenantScopedCommand;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;

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
        $path = database_path($tenant->getDatabaseName());

        touch($path);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $tenant;
    }
}
