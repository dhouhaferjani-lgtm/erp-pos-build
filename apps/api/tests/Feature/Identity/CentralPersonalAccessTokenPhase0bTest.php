<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Infrastructure\CentralPersonalAccessToken;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Tests\TestCase;

/**
 * T6 Phase 0b — Sanctum reads the personal access token from the CENTRAL
 * database while the `tokenable` User resolves in the active TENANT database.
 *
 * After the flip, `personal_access_tokens` lives only in the central database;
 * it does not exist in any tenant database. So once the pre-auth resolver
 * initializes tenancy (swapping the default connection to the tenant database),
 * Sanctum's token lookup MUST read from central or it would query a table that
 * does not exist in the tenant DB. This is exactly what
 * {@see CentralPersonalAccessToken} guarantees, and what this test pins.
 *
 * PG-only; no RefreshDatabase (CREATE DATABASE cannot run inside a transaction).
 */
class CentralPersonalAccessTokenPhase0bTest extends TestCase
{
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-per-tenant PAT resolution is PostgreSQL-only.');
        }

        // Real DB-per-tenant mode so tenancy()->initialize() swaps to the tenant DB.
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
            DB::connection('central')->table('personal_access_tokens')
                ->where('tokenable_type', User::class)->delete();

            try {
                DB::purge('tenant');
                $this->tenant->database()->manager()->deleteDatabase($this->tenant);
            } catch (\Throwable) {
                // best-effort
            }

            DB::connection('central')->table('domains')->where('tenant_id', $this->tenant->id)->delete();
            DB::connection('central')->table('tenants')->where('id', $this->tenant->id)->delete();
        }

        parent::tearDown();
    }

    public function test_bearer_auth_reads_pat_from_central_while_tokenable_resolves_in_tenant(): void
    {
        // Sanctum must be configured to use the central-pinned PAT model.
        $this->assertSame(CentralPersonalAccessToken::class, Sanctum::personalAccessTokenModel());

        $this->tenant = Tenant::factory()->create(['slug' => 'pat'.Str::lower(Str::random(10))]);
        Bus::dispatchSync(new CreateDatabase($this->tenant));
        Bus::dispatchSync(new MigrateDatabase($this->tenant));

        tenancy()->initialize($this->tenant);

        // The tenant database has NO personal_access_tokens table — it is central.
        $this->assertFalse(
            Schema::hasTable('personal_access_tokens'),
            'personal_access_tokens must not exist in the tenant database.',
        );

        // The tokenable User lives in the tenant database.
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'PAT User',
            'email' => 'pat-user@example.test',
            'password' => Hash::make('password-123'),
        ]);

        // createToken uses the configured (central-pinned) PAT model, so the row
        // is written to the central database even though the default connection
        // is currently the tenant database.
        $plainTextToken = $user->createToken('pos-terminal', ['tenant:'.$this->tenant->id])->plainTextToken;

        // Resolve the token the way Sanctum's guard does — AFTER tenancy init.
        $resolved = CentralPersonalAccessToken::findToken($plainTextToken);

        $this->assertNotNull($resolved, 'The PAT must be found via the central connection after tenancy init.');
        // The token row is read from the central connection, NOT the swapped-in
        // tenant connection (where the table does not even exist).
        $this->assertNotSame('tenant', $resolved->getConnection()->getName());
        $this->assertSame(config('tenancy.database.central_connection'), $resolved->getConnection()->getName());

        // The tokenable resolves to the tenant-side user.
        $tokenable = $resolved->tokenable;
        $this->assertInstanceOf(User::class, $tokenable);
        $this->assertSame($user->id, $tokenable->id);
        $this->assertTrue($resolved->can('tenant:'.$this->tenant->id));
    }
}
