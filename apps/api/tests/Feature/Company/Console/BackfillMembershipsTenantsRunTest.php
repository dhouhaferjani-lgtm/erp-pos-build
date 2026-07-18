<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Console;

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
 * Real PostgreSQL database-per-tenant regression for command registration
 * timing. No RefreshDatabase: CREATE DATABASE cannot run in a transaction.
 */
final class BackfillMembershipsTenantsRunTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The tenants:run membership regression is PostgreSQL-only.');
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

        if ($this->tenant !== null) {
            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (\Throwable) {
                // Best effort; the randomized tenant id keeps leaked DB names unique.
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_tenants_run_maps_two_named_users_only_inside_the_physical_tenant_database(): void
    {
        $this->tenant = Tenant::factory()->create([
            'slug' => 'membership-run-'.Str::lower(Str::random(10)),
        ]);

        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));

        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $firstUser = (string) Str::uuid();
        $secondUser = (string) Str::uuid();

        tenancy()->initialize($this->tenant);
        $tenantDb = DB::connection();
        $now = now();

        foreach ([$companyA => 'A', $companyB => 'B'] as $companyId => $name) {
            $tenantDb->table('companies')->insert([
                'id' => $companyId,
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'legal_name' => $name.' SARL',
                'country_code' => 'FR',
                'currency' => 'EUR',
                'locale' => 'fr',
                'timezone' => 'Europe/Paris',
                'date_format' => 'd/m/Y',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([$firstUser, $secondUser] as $index => $userId) {
            $tenantDb->table('users')->insert([
                'id' => $userId,
                'tenant_id' => $this->tenant->id,
                'name' => 'User '.($index + 1),
                'email' => "membership-run-{$index}@example.test",
                'password' => 'not-used',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        tenancy()->end();

        $centralMembershipCount = DB::connection('central')->table('user_company_memberships')->count();
        $this->assertSame(0, $centralMembershipCount);
        $this->assertFalse(DB::connection('central')->table('companies')->where('id', $companyA)->exists());

        $this->artisan(sprintf(
            'tenants:run users:backfill-memberships --tenants=%s --option=company=%s --option=user=%s,%s',
            $this->tenant->id,
            $companyA,
            $firstUser,
            $secondUser,
        ))->assertSuccessful();

        $this->assertFalse(tenancy()->initialized);
        $this->assertSame(
            $centralMembershipCount,
            DB::connection('central')->table('user_company_memberships')->count(),
        );

        tenancy()->initialize($this->tenant);

        $memberships = DB::table('user_company_memberships')
            ->whereIn('user_id', [$firstUser, $secondUser])
            ->orderBy('user_id')
            ->get();

        $this->assertCount(2, $memberships);
        $this->assertSame([$companyA], $memberships->pluck('company_id')->unique()->values()->all());
        $this->assertSame(0, DB::table('user_company_memberships')->where('company_id', $companyB)->count());
        $this->assertTrue($memberships->every(
            static fn (object $membership): bool => $membership->allowed_location_ids === null
                && ! (bool) $membership->is_primary
                && $membership->role === 'viewer'
                && $membership->status === 'active',
        ));
    }
}
