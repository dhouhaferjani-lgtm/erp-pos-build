<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Broadcasting\Support\TestBroadcaster;
use Tests\TestCase;

/**
 * Behavioral integration tests for POST /broadcasting/auth — the
 * load-bearing ground-truth check for the api.broadcast-channels
 * cluster's tenant-isolation invariant.
 *
 * The static analyzer at
 * tests/Architecture/BroadcastChannelTenantContextTest.php catches the
 * obvious bypass shapes at code-review time but cannot enforce
 * data-flow / late-binding / truthy-non-bool invariants — see that
 * test's class-level docblock for the full known-limits list.
 *
 * This test exercises the actual broadcast auth endpoint with
 * Sanctum personal-access-token auth (matches the POS auth shape) for
 * each of the 5 production channels × 3 input shapes:
 *
 *   - own_tenant_own_company       → expect 200 (authorized)
 *   - cross_tenant                 → expect 403 (denied)
 *   - cross_company_same_tenant    → expect 403 (denied)
 *
 * 5 channels × 3 inputs = 15 tests. Each denial test also asserts the
 * response body does NOT echo the foreign tenant/company IDs (defense
 * against information leak via error messages — mirrors the round-1
 * pattern from api.webhooks-incoming).
 *
 * Test data setup is shared via {@see seedTenantAndCompanies}:
 *   - Tenant A with companyA1 + companyA2.
 *   - Tenant B with companyB1.
 *   - userA with active UserCompanyMembership in companyA1 ONLY.
 *
 * Cross-company-same-tenant denial uses companyA2 (same tenant as
 * userA, but different company; user has no membership in
 * companyA2).
 */
final class BroadcastChannelAuthEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pusher-style socket id required by the broadcast auth endpoint.
     * The exact value doesn't matter for the auth check — Laravel
     * routes the request through the channel-name binding, not the
     * socket id.
     */
    private const SOCKET_ID = '12345.67890';

    protected function setUp(): void
    {
        parent::setUp();

        // The default `null` broadcast driver (phpunit.xml:
        // BROADCAST_CONNECTION=null) does NOT run channel auth closures —
        // its `auth()` method is a no-op, so every /broadcasting/auth call
        // returns 200 with an empty body regardless of closure return.
        // Register a TestBroadcaster (see Support/TestBroadcaster.php)
        // that delegates auth to the abstract Broadcaster's
        // `verifyUserCanAccessChannel`, which throws 403 when the closure
        // returns false. This is what gives this integration test
        // ground-truth coverage of the channel auth callbacks.
        Broadcast::extend('test', fn ($app, $config) => new TestBroadcaster);
        config()->set('broadcasting.connections.test', ['driver' => 'test']);
        config()->set('broadcasting.default', 'test');

        // Channels were registered on the prior (null) driver during
        // BroadcastServiceProvider::boot. The new TestBroadcaster instance
        // starts with an empty channels array, so re-include
        // routes/channels.php to register the 5 production channels on
        // the test driver. The require statement in the service provider
        // calls `Broadcast::channel(...)` which proxies to the current
        // default driver — calling it again now lands on TestBroadcaster.
        require base_path('routes/channels.php');
    }

    /**
     * @return array{
     *     tenantA: Tenant,
     *     tenantB: Tenant,
     *     companyA1: Company,
     *     companyA2: Company,
     *     companyB1: Company,
     *     userA: User,
     * }
     */
    private function seedTenantAndCompanies(): array
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $companyA1 = Company::factory()->create(['tenant_id' => $tenantA->id]);
        $companyA2 = Company::factory()->create(['tenant_id' => $tenantA->id]);
        $companyB1 = Company::factory()->create(['tenant_id' => $tenantB->id]);

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $userA->id,
            'company_id' => $companyA1->id,
            'tenant_id' => $tenantA->id,
            'role' => MembershipRole::Viewer,
        ]);

        return [
            'tenantA' => $tenantA,
            'tenantB' => $tenantB,
            'companyA1' => $companyA1,
            'companyA2' => $companyA2,
            'companyB1' => $companyB1,
            'userA' => $userA,
        ];
    }

    private function postAuth(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/broadcasting/auth', [
            'channel_name' => $channelName,
            'socket_id' => self::SOCKET_ID,
        ]);
    }

    /**
     * Assert the response body does NOT echo the given foreign IDs.
     * Defense against information leak via error messages —
     * sender-controlled tenant/company IDs in the request must not
     * be reflected back in the 403 body.
     *
     * @param  list<string>  $forbiddenSubstrings
     */
    private function assertResponseDoesNotLeakIds(TestResponse $response, array $forbiddenSubstrings): void
    {
        $body = (string) $response->getContent();

        foreach ($forbiddenSubstrings as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "Response body must not echo foreign id `{$forbidden}` (information leak guard)."
            );
        }
    }

    // ===================================================================
    // Channel 1: tenant.{tenantId}.company.{companyId}.product.{productId}
    // ===================================================================

    public function test_product_channel_authorizes_own_tenant_own_company(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $productId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA1']->id}.product.{$productId}",
        );

        $response->assertStatus(200);
    }

    public function test_product_channel_rejects_cross_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $productId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantB']->id}.company.{$ctx['companyB1']->id}.product.{$productId}",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['tenantB']->id,
            $ctx['companyB1']->id,
        ]);
    }

    public function test_product_channel_rejects_cross_company_same_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $productId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA2']->id}.product.{$productId}",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['companyA2']->id,
        ]);
    }

    // ===================================================================
    // Channel 2: tenant.{tenantId}.company.{companyId}.imports
    // ===================================================================

    public function test_imports_channel_authorizes_own_tenant_own_company(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA1']->id}.imports",
        );

        $response->assertStatus(200);
    }

    public function test_imports_channel_rejects_cross_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantB']->id}.company.{$ctx['companyB1']->id}.imports",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['tenantB']->id,
            $ctx['companyB1']->id,
        ]);
    }

    public function test_imports_channel_rejects_cross_company_same_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA2']->id}.imports",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['companyA2']->id,
        ]);
    }

    // ===================================================================
    // Channel 3: tenant.{tenantId}.company.{companyId}.partners
    // ===================================================================

    public function test_partners_channel_authorizes_own_tenant_own_company(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA1']->id}.partners",
        );

        $response->assertStatus(200);
    }

    public function test_partners_channel_rejects_cross_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantB']->id}.company.{$ctx['companyB1']->id}.partners",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['tenantB']->id,
            $ctx['companyB1']->id,
        ]);
    }

    public function test_partners_channel_rejects_cross_company_same_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA2']->id}.partners",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['companyA2']->id,
        ]);
    }

    // ===================================================================
    // Channel 4: tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}
    // ===================================================================

    public function test_pos_terminal_channel_authorizes_own_tenant_own_company(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $terminalId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA1']->id}.pos.terminal.{$terminalId}",
        );

        $response->assertStatus(200);
    }

    public function test_pos_terminal_channel_rejects_cross_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $terminalId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantB']->id}.company.{$ctx['companyB1']->id}.pos.terminal.{$terminalId}",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['tenantB']->id,
            $ctx['companyB1']->id,
        ]);
    }

    public function test_pos_terminal_channel_rejects_cross_company_same_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();
        $terminalId = (string) Str::uuid();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA2']->id}.pos.terminal.{$terminalId}",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['companyA2']->id,
        ]);
    }

    // ===================================================================
    // Channel 5: tenant.{tenantId}.company.{companyId}.pos.kitchen
    // ===================================================================

    public function test_pos_kitchen_channel_authorizes_own_tenant_own_company(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA1']->id}.pos.kitchen",
        );

        $response->assertStatus(200);
    }

    public function test_pos_kitchen_channel_rejects_cross_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantB']->id}.company.{$ctx['companyB1']->id}.pos.kitchen",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['tenantB']->id,
            $ctx['companyB1']->id,
        ]);
    }

    public function test_pos_kitchen_channel_rejects_cross_company_same_tenant(): void
    {
        $ctx = $this->seedTenantAndCompanies();

        $response = $this->postAuth(
            $ctx['userA'],
            "private-tenant.{$ctx['tenantA']->id}.company.{$ctx['companyA2']->id}.pos.kitchen",
        );

        $response->assertStatus(403);
        $this->assertResponseDoesNotLeakIds($response, [
            $ctx['companyA2']->id,
        ]);
    }
}
