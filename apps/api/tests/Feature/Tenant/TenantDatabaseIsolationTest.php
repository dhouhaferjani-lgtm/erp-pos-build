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
use Tests\TestCase;

/**
 * T6 Phase 0b — TWO real tenant databases, NO cross-tenant leakage.
 *
 * The flip test proves the structural separation for one tenant. This proves
 * the actual isolation guarantee: data written inside tenant A's database is
 * invisible from tenant B's database and vice versa — because each tenant is a
 * distinct physical PostgreSQL database, not a row-level filter that a missing
 * `where tenant_id = ?` could leak past.
 *
 * PG-only, DB-per-tenant mode, no RefreshDatabase (CREATE DATABASE cannot run
 * inside a transaction). Both tenant databases are dropped in tearDown.
 */
class TenantDatabaseIsolationTest extends TestCase
{
    /** @var array<int, Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-per-tenant isolation is PostgreSQL-only.');
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
                // best-effort
            }
            DB::connection('central')->table('tenants')->where('id', $tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_data_written_in_one_tenant_database_is_invisible_from_another(): void
    {
        $tenantA = $this->provisionTenant('iso-a');
        $tenantB = $this->provisionTenant('iso-b');

        // The two tenants resolve to two DIFFERENT physical databases.
        $this->assertNotSame($tenantA->database()->getName(), $tenantB->database()->getName());

        // Seed a distinct company inside each tenant's own database.
        $companyA = $this->seedCompanyInTenant($tenantA, 'Acme (tenant A)');
        $companyB = $this->seedCompanyInTenant($tenantB, 'Globex (tenant B)');

        // --- Tenant A sees ONLY its own company ---
        tenancy()->initialize($tenantA);
        $this->assertSame(1, DB::table('companies')->count(), 'Tenant A database must contain exactly its own company.');
        $this->assertTrue(DB::table('companies')->where('id', $companyA)->exists());
        $this->assertFalse(
            DB::table('companies')->where('id', $companyB)->exists(),
            'Tenant B\'s company must NOT be visible from tenant A\'s database.',
        );
        $this->assertSame('Acme (tenant A)', DB::table('companies')->value('name'));
        tenancy()->end();

        // --- Tenant B sees ONLY its own company ---
        tenancy()->initialize($tenantB);
        $this->assertSame(1, DB::table('companies')->count(), 'Tenant B database must contain exactly its own company.');
        $this->assertTrue(DB::table('companies')->where('id', $companyB)->exists());
        $this->assertFalse(
            DB::table('companies')->where('id', $companyA)->exists(),
            'Tenant A\'s company must NOT be visible from tenant B\'s database.',
        );
        $this->assertSame('Globex (tenant B)', DB::table('companies')->value('name'));
        tenancy()->end();
    }

    private function provisionTenant(string $slugPrefix): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slugPrefix.Str::lower(Str::random(8))]);
        $this->tenants[] = $tenant;

        Bus::dispatchSync(new CreateDatabase($tenant));
        Bus::dispatchSync(new MigrateDatabase($tenant));

        return $tenant;
    }

    private function seedCompanyInTenant(Tenant $tenant, string $name): string
    {
        $id = (string) Str::uuid();

        tenancy()->initialize($tenant);
        DB::table('companies')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'name' => $name,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        tenancy()->end();

        return $id;
    }
}
