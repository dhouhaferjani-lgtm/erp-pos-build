<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Exceptions\TenantUnavailableException;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P1-4 (Codex 2026-05-25): TenancyResolver fails OPEN.
 *
 * initializeIfProvisioned() catches every Throwable -> false and returns false
 * when databaseExists() is false; ResolveTenancy ignores the false and lets the
 * request continue on the un-switched default connection. That is fine for the
 * Phase 0a single public schema, but UNSOUND post-flip: a request carrying a
 * present tenant source whose tenant DB cannot be initialized must FAIL CLOSED
 * before downstream middleware (auth:sanctum) rather than query the wrong
 * connection.
 *
 * These tests pin: (a) single-schema mode (default) stays a no-op (the existing
 * ResolveTenancyMiddlewareTest covers the happy path); (b) DB-per-tenant mode
 * fails closed when a present tenant cannot be initialized.
 */
class TenancyResolverFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A probe route under the real `api` group (which carries ResolveTenancy
        // before auth:sanctum). The closure is a stand-in for "downstream
        // middleware / handler": if we reach it, the request did NOT fail closed.
        Route::middleware('api')->get('/__test/failclosed-probe', fn (Request $request) => response()->json([
            'reached_downstream' => true,
            'resolved_tenant_id' => $request->attributes->get('resolved_tenant_id'),
        ]));
    }

    public function test_single_schema_mode_skips_silently_and_does_not_fail_closed(): void
    {
        config(['tenancy_resolver.db_per_tenant' => false]);
        [$tenant, $user] = $this->makeUser('fc-single', 'fc-single@example.com');
        $token = $user->createToken('api', ['tenant:'.$tenant->id, '*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/__test/failclosed-probe');

        // No per-tenant DB exists yet; the resolver records the tenant but the
        // request proceeds normally (no fail-closed in single-schema mode).
        $response->assertOk()
            ->assertJsonPath('reached_downstream', true)
            ->assertJsonPath('resolved_tenant_id', $tenant->id);
    }

    public function test_resolver_returns_false_in_single_schema_mode(): void
    {
        config(['tenancy_resolver.db_per_tenant' => false]);
        [$tenant] = $this->makeUser('fc-bool', 'fc-bool@example.com');

        // No exception, just a benign false (DB switch skipped).
        $this->assertFalse(app(TenancyResolver::class)->initializeIfProvisioned($tenant));
    }

    public function test_db_mode_fails_closed_when_present_tenant_cannot_initialize(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);
        [$tenant] = $this->makeUser('fc-db', 'fc-db@example.com');

        // In DB mode, a present tenant whose database does not exist / cannot be
        // initialized must throw rather than silently skip.
        $this->expectException(TenantUnavailableException::class);
        app(TenancyResolver::class)->initializeIfProvisioned($tenant);
    }

    public function test_db_mode_present_uninitializable_tenant_never_reaches_downstream(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);
        [$tenant, $user] = $this->makeUser('fc-http', 'fc-http@example.com');
        $token = $user->createToken('api', ['tenant:'.$tenant->id, '*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/__test/failclosed-probe');

        // 503 from the pre-auth resolver — the downstream closure is never run.
        $response->assertStatus(503);
        $this->assertNull($response->json('reached_downstream'));
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
