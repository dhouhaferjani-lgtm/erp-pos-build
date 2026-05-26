<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Middleware\ResolveTenancy;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResolveTenancyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe route: returns whatever tenant id the pre-auth resolver
        // recorded on the request. Exercised through the real `api` group so
        // this also proves the resolver is wired into that group.
        Route::middleware('api')->get('/__test/resolved-tenant', function (Request $request) {
            return response()->json([
                'resolved_tenant_id' => $request->attributes->get('resolved_tenant_id'),
            ]);
        });

        // The cookie/session branch only applies to the `web` group (it starts
        // the session). Mirror the Identity auth routes, which live under `web`.
        Route::middleware('web')->get('/__test/resolved-tenant-web', function (Request $request) {
            return response()->json([
                'resolved_tenant_id' => $request->attributes->get('resolved_tenant_id'),
            ]);
        });
    }

    public function test_bearer_token_resolves_tenant_with_no_tenant_header(): void
    {
        [$tenant, $user] = $this->makeUser('bearer-org', 'bearer@example.com');
        $token = $user->createToken('api', ['tenant:'.$tenant->id, '*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/__test/resolved-tenant');

        $response->assertOk()
            ->assertJsonPath('resolved_tenant_id', $tenant->id);
    }

    public function test_session_tenant_id_is_resolved_on_the_cookie_branch(): void
    {
        [$tenant] = $this->makeUser('cookie-org', 'cookie@example.com');

        $response = $this->withSession(['tenant_id' => $tenant->id])
            ->getJson('/__test/resolved-tenant-web');

        $response->assertOk()
            ->assertJsonPath('resolved_tenant_id', $tenant->id);
    }

    public function test_no_token_and_no_session_resolves_nothing(): void
    {
        $response = $this->getJson('/__test/resolved-tenant');

        $response->assertOk()
            ->assertJsonPath('resolved_tenant_id', null);
    }

    public function test_resolver_does_not_break_the_request_in_the_current_single_schema_setup(): void
    {
        // Today no per-tenant schema exists, so initializeIfProvisioned() must
        // be a no-op for the DB switch — the request still succeeds and data is
        // still read from the shared (public) schema.
        [$tenant, $user] = $this->makeUser('safe-org', 'safe@example.com');
        $token = $user->createToken('api', ['tenant:'.$tenant->id, '*'])->plainTextToken;

        // The /me endpoint loads the User; if the resolver wrongly switched to a
        // non-existent tenant schema this would 500 / fail to find the user.
        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('data.email', 'safe@example.com');
        $this->assertFalse(tenancy()->initialized, 'No schema exists yet, so tenancy must not be initialized.');
    }

    /**
     * The middleware class exists and is the pre-auth resolver.
     */
    public function test_middleware_class_exists(): void
    {
        $this->assertTrue(class_exists(ResolveTenancy::class));
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeUser(string $slug, string $email): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        return [$tenant, $user];
    }
}
