<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * T6 — rolling tenant-migration runner (`tenants:migrate-rolling`).
 *
 * Proves the command rolls a NEW pending tenant migration out across ALL
 * already-provisioned per-tenant databases, isolating per-tenant failures and
 * leaving the central database untouched. PG-only and deliberately does NOT use
 * RefreshDatabase: PostgreSQL cannot run `CREATE DATABASE` inside a transaction,
 * which RefreshDatabase would open. The test creates real databases and drops
 * them in tearDown.
 */
class RollingTenantMigrationTest extends TestCase
{
    /** @var list<Tenant> */
    private array $tenants = [];

    private ?string $fixtureDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The rolling tenant-migration runner is PostgreSQL-only.');
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
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (\Throwable) {
                // best-effort: leaked test databases use unique random slugs.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        if ($this->fixtureDir !== null && File::isDirectory($this->fixtureDir)) {
            File::deleteDirectory($this->fixtureDir);
        }

        parent::tearDown();
    }

    public function test_rolls_a_pending_migration_out_to_all_provisioned_tenants(): void
    {
        // Two tenants provisioned with the CURRENT tenant migration set (no fixture).
        $a = $this->provisionTenant();
        $b = $this->provisionTenant();

        $table = 'rolling_probe_'.Str::lower(Str::random(8));

        // A new pending tenant migration appears AFTER both DBs were provisioned.
        $this->stagePendingTenantMigration($table);

        $exit = Artisan::call('tenants:migrate-rolling', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        // Both tenant DBs received the new table.
        $this->assertTenantHasTable($a, $table);
        $this->assertTenantHasTable($b, $table);

        // The central database was NOT touched by the tenant migration.
        $this->assertFalse(
            Schema::connection('central')->hasTable($table),
            'A tenant migration must NEVER create its table in the central database.',
        );
    }

    public function test_is_idempotent_when_a_tenant_is_already_up_to_date(): void
    {
        $a = $this->provisionTenant();
        $table = 'rolling_probe_'.Str::lower(Str::random(8));
        $this->stagePendingTenantMigration($table);

        // First roll applies the migration.
        $this->assertSame(0, Artisan::call('tenants:migrate-rolling', ['--force' => true]));
        $this->assertTenantHasTable($a, $table);

        // Second roll is a no-op: nothing pending, still SUCCESS, no error, and
        // the table is unchanged (Stancl's per-tenant `migrations` table records
        // the applied migration, so a re-run finds nothing pending).
        $exit = Artisan::call('tenants:migrate-rolling', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTenantHasTable($a, $table);
        $this->assertSame(1, $this->countMatchingMigrations($a, $table));
    }

    public function test_isolates_a_single_tenant_failure_and_still_migrates_the_rest(): void
    {
        $good = $this->provisionTenant();
        $broken = $this->provisionTenant();

        $table = 'rolling_probe_'.Str::lower(Str::random(8));

        // Stage a pending tenant migration that throws ONLY for the broken tenant
        // (keyed off the active tenant id at migrate time) and creates the table for
        // everyone else. This is a real migration-level failure exercised through the
        // built-in `tenants:migrate`, not a DB-availability quirk. The runner must
        // collect the failure and CONTINUE to the healthy tenant rather than aborting.
        $this->stageTenantConditionalFailingMigration($table, $broken->getTenantKey());

        // Aggregate exit is FAILURE (a failure was collected), but the healthy
        // tenant was STILL migrated — proving the per-tenant failure was isolated
        // and did not abort the rest of the fleet. The summary names the failed
        // tenant; assert via the dedicated PendingCommand buffer (Artisan::output()
        // is unreliable here because the command nests Artisan::call internally).
        $this->artisan('tenants:migrate-rolling', ['--force' => true])
            ->expectsOutputToContain($broken->getTenantKey())
            ->expectsOutputToContain('1 failed')
            ->assertExitCode(1);

        $this->assertTenantHasTable($good, $table);
        $this->assertTenantMissingTable($broken, $table);
    }

    public function test_compat_mode_is_a_documented_no_op(): void
    {
        // In compat mode the per-tenant databases do not exist — tenant migrations
        // already load into the shared schema. The runner must NOT touch anything
        // (no provisioning, no tenancy init) and must exit SUCCESS with a clear
        // no-op message rather than crashing.
        config(['tenancy_resolver.db_per_tenant' => false]);

        // A tenant row exists, but the command must NOT attempt to migrate it.
        $tenant = Tenant::factory()->create(['slug' => 'compat'.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        $this->artisan('tenants:migrate-rolling', ['--force' => true])
            ->expectsOutputToContain('Database-per-tenant mode is OFF')
            ->assertExitCode(0);

        // No tenant database was created as a side effect.
        $exists = DB::connection('central')->selectOne(
            'select 1 as ok from pg_database where datname = ?',
            [$tenant->database()->getName()],
        );
        $this->assertNull($exists, 'Compat-mode no-op must NOT create any tenant database.');
    }

    private function provisionTenant(): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => 'roll'.Str::lower(Str::random(10))]);
        $this->tenants[] = $tenant;

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    private function assertTenantHasTable(Tenant $tenant, string $table): void
    {
        tenancy()->initialize($tenant);
        $hasTable = Schema::connection('tenant')->hasTable($table);
        tenancy()->end();

        $this->assertTrue(
            $hasTable,
            "Tenant {$tenant->getTenantKey()} database should contain the rolled-out table '{$table}'.",
        );
    }

    private function assertTenantMissingTable(Tenant $tenant, string $table): void
    {
        tenancy()->initialize($tenant);
        $hasTable = Schema::connection('tenant')->hasTable($table);
        tenancy()->end();

        $this->assertFalse(
            $hasTable,
            "Tenant {$tenant->getTenantKey()} migration failed, so table '{$table}' must NOT exist (the failed migration rolled back).",
        );
    }

    /**
     * How many rows in the tenant's `migrations` ledger correspond to the staged
     * fixture migration (`*_create_{$table}_table`). Used to prove a re-run does
     * not re-apply an already-applied migration (idempotency).
     */
    private function countMatchingMigrations(Tenant $tenant, string $table): int
    {
        tenancy()->initialize($tenant);
        $count = DB::connection('tenant')
            ->table('migrations')
            ->where('migration', 'like', "%_create_{$table}_table")
            ->count();
        tenancy()->end();

        return $count;
    }

    /**
     * Write a throwaway tenant migration into a temp directory and append that
     * directory to the configured tenant migration path so the runner picks it
     * up as "pending" for already-provisioned tenant databases.
     */
    private function stagePendingTenantMigration(string $table): void
    {
        $body = <<<PHP
                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->uuid('id')->primary();
                    \$table->timestamps();
                });
        PHP;

        $this->writeFixtureMigration($table, $body);
    }

    /**
     * Stage a pending tenant migration that throws while migrating the given
     * tenant key and creates the table for everyone else. Exercises a real
     * migration-level failure on exactly one tenant.
     */
    private function stageTenantConditionalFailingMigration(string $table, string $failingTenantKey): void
    {
        $body = <<<PHP
                if ((string) optional(tenant())->getTenantKey() === '{$failingTenantKey}') {
                    throw new \\RuntimeException('Simulated migration failure for tenant {$failingTenantKey}.');
                }

                Schema::create('{$table}', function (Blueprint \$table) {
                    \$table->uuid('id')->primary();
                    \$table->timestamps();
                });
        PHP;

        $this->writeFixtureMigration($table, $body);
    }

    /**
     * Write a throwaway tenant migration whose `up()` body is `$upBody`, into a
     * temp directory, and append that directory to the configured tenant
     * migration path so the runner treats it as pending.
     */
    private function writeFixtureMigration(string $table, string $upBody): void
    {
        $this->fixtureDir = storage_path('framework/testing/rolling-tenant-migrations-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->fixtureDir);

        $timestamp = now()->format('Y_m_d_His');
        $file = $this->fixtureDir."/{$timestamp}_create_{$table}_table.php";

        File::put($file, <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        {$upBody}
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP);

        /** @var array<int, string> $paths */
        $paths = (array) config('tenancy.migration_parameters.--path', []);
        $paths[] = $this->fixtureDir;
        config(['tenancy.migration_parameters.--path' => $paths]);
    }
}
