<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Tests\TestCase;

/**
 * T6 Phase 0b — the Stancl manager flip.
 *
 * config/tenancy.php previously declared the `pgsql` manager twice
 * (PostgreSQLDatabaseManager then PostgreSQLSchemaManager); PHP keeps the last,
 * so PostgreSQLSchemaManager (row-level / shared-schema) was effective. The
 * flip removes the duplicate so PostgreSQLDatabaseManager (one physical database
 * per tenant) is the one Stancl uses.
 */
class TenancyManagerConfigTest extends TestCase
{
    public function test_pgsql_manager_is_the_database_manager_not_the_schema_manager(): void
    {
        $this->assertSame(
            PostgreSQLDatabaseManager::class,
            config('tenancy.database.managers.pgsql'),
            'The pgsql tenant database manager must be PostgreSQLDatabaseManager (database-per-tenant).',
        );

        $this->assertNotSame(
            PostgreSQLSchemaManager::class,
            config('tenancy.database.managers.pgsql'),
        );
    }

    public function test_tenant_migrations_path_targets_the_tenant_directory(): void
    {
        $paths = config('tenancy.migration_parameters.--path');

        $this->assertContains(database_path('migrations/tenant'), $paths);
    }
}
