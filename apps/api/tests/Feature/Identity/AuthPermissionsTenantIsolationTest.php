<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * api.auth-permissions cluster — Section 15 (the LAST architectural cluster).
 *
 * Pins Invariant A of the master plan §15: token revocation lifecycle.
 *
 *   A.1 — Tenant suspension revokes all user tokens (real today via
 *         SuperAdminController::suspendTenant).
 *   A.2 — Tenant deletion revokes all user tokens BEFORE the
 *         users.tenant_id ON DELETE CASCADE fires (forward-compat;
 *         no admin endpoint exposes this today).
 *   A.3 — User tenant_id change revokes the user's tokens (forward-compat;
 *         no admin endpoint exposes this today).
 *
 * Why behavioral DB-state assertions on personal_access_tokens (count
 * before/after) instead of mocks: cleaner evidence, matches module-gating
 * cluster's lesson #4 preference even though Tenant + User are NOT final
 * (mocks would also work). Per-test docblocks document the production-path
 * status (forward-compat where applicable) so reviewer doesn't conflate
 * "no production path triggers this" with "test is vacuous."
 *
 * Triage: docs/superpowers/audits/2026-05-08-api-auth-permissions-triage.md
 */
final class AuthPermissionsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Invariant A.1 — Tenant suspension revokes all user tokens.
     *
     * Production path: SuperAdminController::suspendTenant calls
     * `$tenant->update(['status' => 'suspended'])` which fires Eloquent
     * `updating`/`updated`. The TenantObserver's `updated` hook detects
     * `wasChanged('status') && status === Suspended` and revokes tokens
     * for every user in the tenant.
     */
    public function test_tenant_suspension_revokes_all_user_tokens(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA1 = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);
        $userA2 = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);
        $userB1 = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => UserStatus::Active]);
        $userB2 = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => UserStatus::Active]);

        $userA1->createToken('test-a1');
        $userA2->createToken('test-a2');
        $userB1->createToken('test-b1');
        $userB2->createToken('test-b2');

        $this->assertSame(4, DB::table('personal_access_tokens')->count());

        $tenantA->update(['status' => TenantStatus::Suspended]);

        $tenantATokens = DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', [$userA1->id, $userA2->id])
            ->where('tokenable_type', User::class)
            ->count();
        $this->assertSame(0, $tenantATokens, 'Tenant A users tokens should be revoked on suspension');

        $tenantBTokens = DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', [$userB1->id, $userB2->id])
            ->where('tokenable_type', User::class)
            ->count();
        $this->assertSame(2, $tenantBTokens, 'Tenant B users tokens should be unaffected');
    }

    /**
     * Invariant A.1 — non-Suspended status changes do not revoke tokens.
     *
     * Sanity check that the suspension hook is selective. A tenant
     * transitioning Active → Pending must NOT revoke tokens.
     */
    public function test_tenant_status_change_other_than_suspend_does_not_revoke(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $user->createToken('test');

        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $tenant->update(['status' => TenantStatus::Pending]);

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->count(),
            'Pending status change should not revoke tokens; only Suspended triggers revocation',
        );
    }

    /**
     * Invariant A.1 — re-suspension after activation is idempotent on the
     * new token-set.
     *
     * Suspend → Activate → user re-logs in → Suspend again → new token revoked.
     */
    public function test_tenant_re_suspend_revokes_new_tokens_idempotently(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $user->createToken('first');

        $tenant->update(['status' => TenantStatus::Suspended]);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $tenant->update(['status' => TenantStatus::Active]);
        $user->createToken('second');
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $tenant->update(['status' => TenantStatus::Suspended]);
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->count(),
            'Re-suspension after activation should revoke the new token-set',
        );
    }

    /**
     * Invariant A.2 — Tenant deletion revokes all user tokens.
     *
     * Forward-compat: no SuperAdminController endpoint exposes tenant
     * deletion today. Test drives `$tenant->delete()` directly via
     * Eloquent. The hook MUST fire on `Tenant::deleting` (BEFORE the
     * users.tenant_id ON DELETE CASCADE fires) — Eloquent User events
     * do NOT fire on DB-level cascade, so a User-side hook would never
     * run.
     *
     * Verified shape: tokens for the deleted tenant's users are revoked
     * BEFORE the cascade purges users. Other tenants' users are unaffected.
     */
    public function test_tenant_deletion_revokes_all_user_tokens(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA1 = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);
        $userB1 = User::factory()->create(['tenant_id' => $tenantB->id, 'status' => UserStatus::Active]);

        $userA1->createToken('test-a1');
        $userB1->createToken('test-b1');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $userA1Id = $userA1->id;

        $tenantA->delete();

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userA1Id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Tenant A user tokens should be revoked before the cascade purges users',
        );
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userB1->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Tenant B user tokens should be unaffected',
        );
    }

    /**
     * Invariant A.3 — User tenant_id change revokes that user's tokens.
     *
     * Forward-compat: no SuperAdminController endpoint mutates
     * users.tenant_id today. UserController CRUD always pins tenant_id
     * to $currentUser->tenant_id (line 176). Test drives
     * `$user->update(['tenant_id' => $newTenant->id])` directly via Eloquent.
     */
    public function test_user_tenant_change_revokes_old_tokens(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userMoving = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);
        $userStaying = User::factory()->create(['tenant_id' => $tenantA->id, 'status' => UserStatus::Active]);

        $userMoving->createToken('test-moving');
        $userStaying->createToken('test-staying');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $userMoving->update(['tenant_id' => $tenantB->id]);

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userMoving->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Moving user tokens should be revoked on tenant_id change',
        );
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $userStaying->id)
                ->where('tokenable_type', User::class)
                ->count(),
            'Staying user tokens should be unaffected',
        );
    }

    /**
     * Sanity — non-tenant_id User updates do not revoke tokens.
     *
     * The User observer should be selective: changing name/email/etc.
     * must NOT trigger token revocation.
     */
    public function test_user_non_tenant_change_does_not_revoke_tokens(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        $user->createToken('test');

        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $user->update(['name' => 'New Name']);

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->count(),
            'Non-tenant_id user updates should not revoke tokens',
        );
    }
}
