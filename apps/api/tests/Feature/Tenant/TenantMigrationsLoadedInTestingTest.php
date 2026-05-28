<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T6 Phase 0b — tenant migrations are loaded into the single test connection.
 *
 * In production, tenant migrations live in database/migrations/tenant/ and run
 * only inside each per-tenant database (via Stancl). The compat test suite,
 * however, runs on ONE connection with RefreshDatabase — which only migrates
 * database/migrations/. Once ~141 migrations move to tenant/, every existing
 * feature test would lose its tables.
 *
 * AppServiceProvider therefore registers the tenant migration path in the
 * testing environment so RefreshDatabase rebuilds the complete schema on the
 * single connection. This test guards that wiring: a table created ONLY by a
 * tenant/ migration must exist after a normal RefreshDatabase boot.
 */
class TenantMigrationsLoadedInTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_directory_migrations_run_under_refresh_database(): void
    {
        // `channels` is created exclusively by a database/migrations/tenant/
        // migration. If the tenant path were not registered for testing, this
        // table would not exist after RefreshDatabase.
        $this->assertTrue(
            Schema::hasTable('channels'),
            'Tenant-directory migrations must be loaded into the test connection.',
        );
    }
}
