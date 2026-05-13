<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * api.auth-permissions cluster — Section 15 (the LAST architectural cluster).
 *
 * Pins Invariant D of the master plan §15: token-tenant-claim defense-in-depth.
 *
 * Every Sanctum personal-access token issued for a tenant user MUST encode
 * the user's tenant_id as a `tenant:<uuid>` ability. The
 * {@see EnforceTokenTenantClaim} middleware verifies that the live
 * `User::tenant_id` matches the claim on every authenticated request, so a
 * token that survived a tenant move (e.g., a stale token bound to a user
 * whose tenant_id has since changed via a future admin endpoint) is
 * rejected at the request boundary even if Invariants A.1/A.2/A.3 (token
 * revocation lifecycle) miss the revocation window.
 *
 * GRANDFATHERING POLICY (option (b) per triage Section C.5)
 *
 * Pre-deploy tokens lack the `tenant:` ability. Rejecting them at deploy
 * would force every active session to re-login (operational disruption).
 * The middleware therefore allows tokens with NO `tenant:` ability through
 * (grandfathering), and only rejects tokens whose claim DOES NOT match
 * the live `User::tenant_id`. After a 30-day token-rotation window where
 * every newly-issued token has the claim, this can optionally tighten to
 * "reject tokens issued before deploy_timestamp" (option (c)) — tracked
 * as a future work item, NOT this PR.
 *
 * SUPER-ADMIN EXEMPTION
 *
 * Super-admin tokens encode `'super-admin'` instead of `tenant:*` (the
 * super-admin pipeline operates cross-tenant by design and the SuperAdmin
 * model has no `tenant_id`). The middleware recognizes the `super-admin`
 * ability marker and exits early.
 *
 * SESSION-COOKIE EXEMPTION
 *
 * SPA requests authenticate via session cookies (Sanctum's
 * `EnsureFrontendRequestsAreStateful`), which produces a `TransientToken`
 * — not a `PersonalAccessToken`. The middleware exits early on
 * non-PAT auth so SPA requests are unaffected.
 *
 * Triage: docs/superpowers/audits/2026-05-08-api-auth-permissions-triage.md
 */
final class EnforceTokenTenantClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Probe route protected by auth:sanctum + the middleware under test.
        // We deliberately omit the `api` middleware group to isolate the
        // middleware logic from unrelated api-group concerns (CSRF,
        // CompanyContextMiddleware company-membership lookup, etc.). The
        // production middleware order is exercised by AuthLifecycleTest.
        Route::middleware(['auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
            ->get('/_test/tenant-claim-probe', fn () => response()->json(['ok' => true]));

        // Super-admin probe route mounting the same middleware to verify the
        // super-admin ability-marker exemption is honored even when the
        // middleware is reachable from the super-admin pipeline.
        Route::middleware(['auth:sanctum-admin', EnforceTokenTenantClaim::class])
            ->get('/_test/super-admin-probe', fn () => response()->json(['ok' => true]));
    }

    /**
     * Branch 1 — token's `tenant:` ability matches the live user.tenant_id → pass.
     */
    public function test_request_with_matching_tenant_claim_passes(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        $token = $user->createToken('test', ['tenant:'.$tenant->id, '*'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_test/tenant-claim-probe');

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }

    /**
     * Branch 2 — token's `tenant:` ability does NOT match user.tenant_id → 401.
     *
     * RED ANCHOR: this test fails on dev tip (response 200) because the
     * middleware does not exist yet. After PR2 lands, the response is 401.
     * This is the bug-fix gate for Invariant D.
     */
    public function test_request_with_mismatching_tenant_claim_rejected_401(): void
    {
        $tenantA = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $tenantB = Tenant::factory()->create(['status' => TenantStatus::Active]);

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'status' => UserStatus::Active,
        ]);

        // Hand-craft a token bound to $user but encoding tenantB's id.
        // Real-world equivalent: user moved A → B but token from A still in flight.
        $token = $user->createToken('test', ['tenant:'.$tenantB->id, '*'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_test/tenant-claim-probe');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_TENANT_MISMATCH')
            ->assertJsonPath('error.message', 'Token tenant claim does not match user tenant.');
    }

    /**
     * Branch 3 — token has NO `tenant:` ability (legacy/grandfathered) → pass.
     *
     * Post-deploy migration window: tokens issued before this PR encode
     * only `'*'`. The middleware allows them through to avoid a
     * deploy-time mass logout. Operational follow-up is to tighten this
     * after a 30-day rotation window.
     */
    public function test_request_with_grandfathered_token_no_claim_passes(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        // Legacy token shape: no `tenant:` ability.
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_test/tenant-claim-probe');

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }

    /**
     * Branch 4 — super-admin token (with `super-admin` ability) on a route
     * also mounting EnforceTokenTenantClaim → pass.
     *
     * The super-admin pipeline is exempt by design; this test pins that
     * the middleware honors the ability-marker exemption even when the
     * super-admin user's tokenable type lacks `tenant_id`.
     */
    public function test_super_admin_token_passes_via_ability_marker(): void
    {
        $admin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test SA',
            'email' => 'sa-claim@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $token = $admin->createToken('test', ['super-admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/_test/super-admin-probe');

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }

    /**
     * Branch 5 — session-cookie auth (TransientToken) → pass without
     * token-claim check.
     *
     * SPA requests authenticate via Sanctum's stateful pipeline; the
     * resulting "token" is a `TransientToken`, not a `PersonalAccessToken`.
     * The middleware must exit early on non-PAT auth so SPAs are
     * unaffected.
     */
    public function test_session_cookie_auth_passes_without_token_check(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::Active]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($user)->getJson('/_test/tenant-claim-probe');

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }
}
