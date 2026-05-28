<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\TenantBackupService;
use App\Modules\Tenant\Application\Services\TenantHealthService;
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
 * T6 Phase 0b — per-tenant infra health snapshot.
 *
 * Combines central directory facts (last backup row) with live Postgres
 * introspection (database existence, size, active connections). PG-only,
 * DB-per-tenant mode, no RefreshDatabase (CREATE DATABASE can't run inside
 * a transaction).
 */
class TenantHealthServiceTest extends TestCase
{
    /** @var array<int, Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Tenant health snapshot is PostgreSQL-only.');
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
                // best-effort
            }
            DB::connection('central')->table('tenant_backups')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_snapshot_reports_db_exists_size_and_connection_count_for_a_provisioned_tenant(): void
    {
        $tenant = $this->provisionTenant('snap');

        $snapshots = $this->makeService()->snapshot();

        $row = $snapshots->firstWhere('tenantId', $tenant->id);
        $this->assertNotNull($row, 'Snapshot must include the provisioned tenant.');
        $this->assertTrue($row->databaseExists);
        $this->assertNotNull($row->databaseSizeBytes);
        $this->assertGreaterThan(0, $row->databaseSizeBytes);
        $this->assertNotNull($row->activeConnections);
        $this->assertGreaterThanOrEqual(0, $row->activeConnections);
    }

    public function test_snapshot_reports_db_missing_when_database_was_never_provisioned(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'unprov'.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        $row = $this->makeService()->snapshotFor($tenant);

        $this->assertFalse($row->databaseExists);
        $this->assertNull($row->databaseSizeBytes);
        $this->assertNull($row->activeConnections);
    }

    public function test_snapshot_surfaces_last_completed_backup_for_a_tenant(): void
    {
        $tenant = $this->provisionTenant('lb');
        config(['tenant_backups.root' => sys_get_temp_dir().'/autoerp-test-health-'.Str::lower(Str::random(8))]);

        $this->app->make(TenantBackupService::class)->backup($tenant);

        $row = $this->makeService()->snapshotFor($tenant);

        $this->assertSame('completed', $row->lastBackupStatus);
        $this->assertNotNull($row->lastBackupCompletedAt);
        $this->assertNull($row->lastBackupError);
    }

    public function test_snapshot_surfaces_last_failed_backup_for_a_tenant(): void
    {
        $tenant = $this->provisionTenant('lf');
        $databaseName = $tenant->database()->getName();
        config(['tenant_backups.root' => sys_get_temp_dir().'/autoerp-test-health-'.Str::lower(Str::random(8))]);

        // Drop the DB so pg_dump fails — the service records status=failed.
        DB::purge('tenant');
        $tenant->database()->manager()->deleteDatabase($tenant);
        $this->assertFalse($this->physicalDatabaseExists($databaseName));

        try {
            $this->app->make(TenantBackupService::class)->backup($tenant);
        } catch (\Throwable) {
            // expected
        }

        $row = $this->makeService()->snapshotFor($tenant);

        $this->assertSame('failed', $row->lastBackupStatus);
        $this->assertNotNull($row->lastBackupError);
    }

    private function makeService(): TenantHealthService
    {
        return $this->app->make(TenantHealthService::class);
    }

    private function provisionTenant(string $slugPrefix): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slugPrefix.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;
        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    private function physicalDatabaseExists(string $databaseName): bool
    {
        return DB::connection('central')->selectOne(
            'select 1 as ok from pg_database where datname = ?',
            [$databaseName],
        ) !== null;
    }
}
