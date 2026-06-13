<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantProvisioningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TenantProvisioningService failed-registration rollback (compensation).
 *
 * Under database-per-tenant, register cannot be a single transaction: the
 * central rows live in the central DB while the user/token live in (or pinned
 * to) different connections. On failure, {@see TenantProvisioningService} drops
 * the tenant DB and deletes the central directory rows via the query builder —
 * which fires NO Eloquent model events, so the TenantObserver's token
 * revocation never runs. A bearer token already minted for the half-created
 * owner would therefore be orphaned in the CENTRAL `personal_access_tokens`
 * table. The rollback must revoke it explicitly.
 *
 * The full provisioning failure path is PG-only (it dispatches CreateDatabase /
 * MigrateDatabase). This test exercises the compensation branch directly in the
 * shared-DB test topology with `databaseCreated = false` (no physical database
 * to drop). Reflection is used because compensate() has no public seam —
 * invoking it is an established pattern in this suite for private orchestration
 * steps.
 *
 * Compensation's central-directory cleanup targets the literal `central`
 * connection (pgsql, unreachable in the sqlite suite), so it throws a
 * QueryException AFTER the revocation step. Because token revocation must run
 * BEFORE the central teardown (the revoker's central_identities fallback needs
 * those rows), the assertions below — pure token DB-state — both prove the
 * revocation happened and pin that ordering: if the revoker were wired after
 * the central deletes, the exception would abort before revocation and the
 * orphaned token would survive.
 */
final class TenantProvisioningCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rollback_revokes_a_token_minted_for_the_half_created_owner(): void
    {
        $tenant = $this->makeTenant('rollback-revoke');
        $otherTenant = $this->makeTenant('rollback-keep');

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'status' => UserStatus::Active]);

        // A token was minted for the owner just before the provisioning failure.
        $owner->createToken('api-token');
        $otherUser->createToken('keep');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        try {
            $this->invokeCompensate($tenant);
        } catch (QueryException) {
            // Central-directory cleanup uses the PG-only `central` connection,
            // unreachable in the sqlite suite. Revocation runs first; the token
            // assertions below stand regardless.
        }

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $owner->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'The orphaned owner token must be revoked on rollback.',
        );
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $otherUser->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'An unrelated tenant token must be untouched.',
        );
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'Rollback '.$slug,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
        ]);
    }

    private function invokeCompensate(Tenant $tenant): void
    {
        $service = $this->app->make(TenantProvisioningService::class);

        $method = new ReflectionMethod($service, 'compensate');
        $method->setAccessible(true);
        // databaseCreated = false: no physical tenant DB exists in the shared-DB
        // test topology, so the rollback exercises only the revocation + central
        // cleanup branch (the path that orphaned the token).
        $method->invoke($service, $tenant, false);
    }
}
