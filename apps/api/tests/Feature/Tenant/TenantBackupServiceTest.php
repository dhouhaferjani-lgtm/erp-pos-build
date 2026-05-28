<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\TenantBackupService;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — per-tenant logical backup.
 *
 * PG-only, DB-per-tenant mode. Provisions a real per-tenant database with the
 * Stancl jobs (no RefreshDatabase: CREATE DATABASE can't run in a transaction),
 * runs pg_dump via TenantBackupService, asserts the file exists, is a real
 * pg_dump custom-format file, and the central tenant_backups row is recorded
 * with completed status + sha256.
 */
class TenantBackupServiceTest extends TestCase
{
    /** @var array<int, Tenant> */
    private array $tenants = [];

    /** @var list<string> */
    private array $backupRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Tenant backups are PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        if (! Schema::hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        $root = sys_get_temp_dir().'/autoerp-test-backups-'.Str::lower(Str::random(8));
        config(['tenant_backups.root' => $root]);
        $this->backupRoots[] = $root;
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

            DB::connection('central')->table('tenant_backups')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        foreach ($this->backupRoots as $root) {
            if (is_dir($root)) {
                File::deleteDirectory($root);
            }
        }

        parent::tearDown();
    }

    public function test_backup_writes_a_pg_dump_file_for_the_tenant_database(): void
    {
        $tenant = $this->provisionTenant('bk');

        $result = $this->makeService()->backup($tenant);

        $this->assertFileExists($result->filePath);
        $this->assertGreaterThan(0, $result->fileSizeBytes);
        $this->assertSame(64, strlen($result->sha256));

        // pg_dump custom-format files start with the magic header "PGDMP".
        $handle = fopen($result->filePath, 'rb');
        $this->assertNotFalse($handle);
        $magic = (string) fread($handle, 5);
        fclose($handle);
        $this->assertSame('PGDMP', $magic, 'File must be a pg_dump custom-format dump.');
    }

    public function test_backup_records_completed_metadata_in_central_tenant_backups(): void
    {
        $tenant = $this->provisionTenant('meta');

        $result = $this->makeService()->backup($tenant);

        $row = DB::connection('central')->table('tenant_backups')
            ->where('id', $result->tenantBackupId)->first();

        $this->assertNotNull($row);
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertSame('completed', $row->status);
        $this->assertSame($result->filePath, $row->file_path);
        $this->assertSame((int) $result->fileSizeBytes, (int) $row->file_size_bytes);
        $this->assertSame($result->sha256, $row->sha256);
        $this->assertNotNull($row->completed_at);
    }

    public function test_backup_throws_when_db_per_tenant_mode_is_disabled(): void
    {
        $tenant = $this->provisionTenant('off');
        config(['tenancy_resolver.db_per_tenant' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/database-per-tenant/');

        $this->makeService()->backup($tenant);
    }

    public function test_backup_records_failure_when_pg_dump_cannot_reach_the_database(): void
    {
        // Provision a tenant row but DROP its database underneath so pg_dump
        // hits a "database does not exist" error path. The service must record
        // status=failed in the central tenant_backups row, not silently swallow.
        $tenant = $this->provisionTenant('fail');
        $databaseName = $tenant->database()->getName();

        DB::purge('tenant');
        $tenant->database()->manager()->deleteDatabase($tenant);
        $this->assertFalse($this->physicalDatabaseExists($databaseName));

        try {
            $this->makeService()->backup($tenant);
            $this->fail('Expected RuntimeException when target database is missing.');
        } catch (RuntimeException) {
            // expected
        }

        $row = DB::connection('central')->table('tenant_backups')
            ->where('tenant_id', $tenant->id)->latest('started_at')->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error_message);
    }

    public function test_backup_retention_deletes_older_completed_backups_beyond_keep(): void
    {
        config(['tenant_backups.keep' => 2]);
        $tenant = $this->provisionTenant('ret');
        $service = $this->makeService();

        $first = $service->backup($tenant);
        // Force distinct timestamp so the retention ORDER BY is unambiguous.
        sleep(1);
        $second = $service->backup($tenant);
        sleep(1);
        $third = $service->backup($tenant);

        $count = DB::connection('central')->table('tenant_backups')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->count();
        $this->assertSame(2, $count, 'Only the last `keep` completed backups must remain.');

        $this->assertFileDoesNotExist($first->filePath, 'Oldest backup file must be deleted.');
        $this->assertFileExists($second->filePath);
        $this->assertFileExists($third->filePath);
    }

    private function makeService(): TenantBackupService
    {
        return $this->app->make(TenantBackupService::class);
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
