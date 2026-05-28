<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;

/**
 * T6 Phase 0b — provision TWO real per-tenant databases with distinct demo data
 * and prove there is no cross-tenant leakage. A dev/staging tool for exercising
 * database-per-tenant locally (Docker) and on staging — including for the
 * pre-existing fiscal-projection PG debt.
 *
 * Requires PostgreSQL + DB-per-tenant mode. Run with:
 *
 *   TENANCY_DB_PER_TENANT=true php artisan db:seed \
 *       --class="Database\Seeders\TwoTenantIsolationDemoSeeder"
 *
 * Re-runnable: it drops and recreates the two demo tenant databases each run.
 */
class TwoTenantIsolationDemoSeeder extends Seeder
{
    private const TENANTS = [
        'demo-tenant-a' => 'Acme (demo tenant A)',
        'demo-tenant-b' => 'Globex (demo tenant B)',
    ];

    public function run(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->command->error('This seeder requires PostgreSQL (database-per-tenant).');

            return;
        }

        if (! (bool) config('tenancy_resolver.db_per_tenant', false)) {
            $this->command->error('Set TENANCY_DB_PER_TENANT=true — database-per-tenant mode must be active.');

            return;
        }

        /** @var array<string, array{tenant: Tenant, company_id: string}> $provisioned */
        $provisioned = [];

        foreach (self::TENANTS as $slug => $companyName) {
            $tenant = $this->reprovisionTenant($slug);
            $companyId = $this->seedCompany($tenant, $companyName);
            $provisioned[$slug] = ['tenant' => $tenant, 'company_id' => $companyId];

            $this->command->info(sprintf(
                '  provisioned %s -> database "%s" (company %s)',
                $slug,
                $tenant->database()->getName(),
                $companyName,
            ));
        }

        $this->assertNoLeak($provisioned);

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    private function reprovisionTenant(string $slug): Tenant
    {
        $existing = Tenant::query()->where('slug', $slug)->first();
        if ($existing !== null) {
            try {
                DB::purge('tenant');
                $existing->database()->manager()->deleteDatabase($existing);
            } catch (\Throwable) {
                // database may not exist yet; ignore
            }
            $existing->delete();
        }

        $tenant = Tenant::factory()->create(['slug' => $slug]);
        (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));
        (new MigrateDatabase($tenant))->handle();

        return $tenant;
    }

    private function seedCompany(Tenant $tenant, string $name): string
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

    /**
     * @param  array<string, array{tenant: Tenant, company_id: string}>  $provisioned
     */
    private function assertNoLeak(array $provisioned): void
    {
        $slugs = array_keys($provisioned);
        [$a, $b] = [$provisioned[$slugs[0]], $provisioned[$slugs[1]]];

        tenancy()->initialize($a['tenant']);
        $aSeesOwn = DB::table('companies')->where('id', $a['company_id'])->exists();
        $aSeesOther = DB::table('companies')->where('id', $b['company_id'])->exists();
        $aCount = DB::table('companies')->count();
        tenancy()->end();

        tenancy()->initialize($b['tenant']);
        $bSeesOwn = DB::table('companies')->where('id', $b['company_id'])->exists();
        $bSeesOther = DB::table('companies')->where('id', $a['company_id'])->exists();
        $bCount = DB::table('companies')->count();
        tenancy()->end();

        $leaked = $aSeesOther || $bSeesOther || $aCount !== 1 || $bCount !== 1 || ! $aSeesOwn || ! $bSeesOwn;

        if ($leaked) {
            $this->command->error('❌ CROSS-TENANT LEAK DETECTED — database-per-tenant isolation is broken.');

            return;
        }

        $this->command->info('✅ No cross-tenant leak: each tenant database contains exactly its own company.');
    }
}
