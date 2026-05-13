<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end rate-limit enforcement tests.
 *
 * Companion to {@see FirstTenantProductionSecurityTest::test_required_rate_limiter_is_registered},
 * which proves the named limiters exist in AppServiceProvider but does NOT
 * prove that the routes are actually wrapped with the corresponding
 * `throttle:*` middleware. The prior round's Opus adversarial review
 * flagged this as P1-5: a refactor that strips `throttle:login` from the
 * login route would leave the limiter registered (test passes) while the
 * production route silently no-ops.
 *
 * These tests close that loop by issuing N+1 actual requests at each
 * limited endpoint and asserting the (N+1)th returns HTTP 429.
 *
 * Coverage:
 * - login                 — 5/min per email or IP → 6th attempt is 429
 * - forgot-password       — 3/hour per email or IP → 4th attempt is 429
 * - reset-password        — 3/hour per email or IP → 4th attempt is 429
 * - register              — 5/15min per IP → 6th attempt is 429
 * - verify-manager-pin    — 3/30s per (IP, user_id) → 4th attempt is 429
 *
 * The CACHE_STORE=array test config (apps/api/phpunit.xml) gives each
 * test process a fresh in-memory cache, so attempts from one test do
 * not leak into another. We additionally call {@see RateLimiter::clear()}
 * defensively for endpoints whose key includes IP (IP is shared across
 * tests in the same process: 127.0.0.1).
 */
final class RateLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Defensive: clear any leftover counters from a sibling test.
        // The named throttle middleware composes the key from the limiter
        // name + by() value (Illuminate\Routing\Middleware\ThrottleRequests::resolveRequestSignature),
        // so we clear by limiter name to be safe. RateLimiter::clear is a
        // no-op for keys that never existed.
        foreach (['login', 'password-reset', 'register', 'document-email', 'pos-terminal-activation'] as $name) {
            RateLimiter::clear($name);
        }
    }

    public function test_login_returns_429_after_five_invalid_attempts(): void
    {
        $email = 'rate-limit-login-'.uniqid().'@test.invalid';

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ]);

            $this->assertNotEquals(
                429,
                $response->status(),
                "Login attempt #{$i} must not be throttled (limit is 5/min); got 429 too early.",
            );
        }

        // 6th attempt must be rate-limited.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_forgot_password_returns_429_after_three_attempts(): void
    {
        $email = 'rate-limit-forgot-'.uniqid().'@test.invalid';

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => $email,
            ]);

            $this->assertNotEquals(
                429,
                $response->status(),
                "forgot-password attempt #{$i} must not be throttled (limit is 3/hour); got 429 too early.",
            );
        }

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $email,
        ]);

        $response->assertStatus(429);
    }

    public function test_reset_password_returns_429_after_three_attempts(): void
    {
        // reset-password and forgot-password share the same `password-reset`
        // limiter (keyed on email). A fresh email guarantees no cross-test
        // bleed inside this method.
        $email = 'rate-limit-reset-'.uniqid().'@test.invalid';

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/v1/auth/reset-password', [
                'email' => $email,
                'token' => 'fake-token',
                'password' => 'whatever-password',
                'password_confirmation' => 'whatever-password',
            ]);

            $this->assertNotEquals(
                429,
                $response->status(),
                "reset-password attempt #{$i} must not be throttled (limit is 3/hour); got 429 too early.",
            );
        }

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email,
            'token' => 'fake-token',
            'password' => 'whatever-password',
            'password_confirmation' => 'whatever-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_register_returns_429_after_five_attempts(): void
    {
        // The register limiter keys on IP. In tests the IP is 127.0.0.1
        // (or empty), so all attempts share the same counter. We seeded
        // RateLimiter::clear('register') in setUp() to start from zero.
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/auth/register', [
                'name' => 'Rate Limit Probe',
                'email' => 'register-probe-'.$i.'@test.invalid',
                'password' => 'Pa$$w0rd-Pa$$w0rd',
                'password_confirmation' => 'Pa$$w0rd-Pa$$w0rd',
                'tenant_name' => 'Probe Tenant',
            ]);

            $this->assertNotEquals(
                429,
                $response->status(),
                "register attempt #{$i} must not be throttled (limit is 5/15min); got 429 too early.",
            );
        }

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rate Limit Probe',
            'email' => 'register-probe-final@test.invalid',
            'password' => 'Pa$$w0rd-Pa$$w0rd',
            'password_confirmation' => 'Pa$$w0rd-Pa$$w0rd',
            'tenant_name' => 'Probe Tenant',
        ]);

        $response->assertStatus(429);
    }

    public function test_verify_manager_pin_returns_429_after_three_attempts(): void
    {
        // Manager-PIN uses a hand-rolled limiter keyed on
        // 'verify-manager-pin:'.$ip.':'.$userId with 3 attempts / 30s.
        // We pre-clear the key to guarantee a fresh counter even if the
        // cache state has been touched elsewhere.
        $tenant = Tenant::create([
            'name' => 'PIN Probe Tenant',
            'slug' => 'pin-probe-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PIN Probe Shop',
            'legal_name' => 'PIN Probe Shop LLC',
            'tax_id' => 'TAX-PIN-PROBE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
        ]);

        $caller = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $caller->id,
            'company_id' => $company->id,
            'role' => 'cashier',
        ]);

        // VerifyManagerPinRequest validates `user_id` exists in the
        // caller's tenant before the controller runs (so the hand-rolled
        // RateLimiter check is downstream of validation). Create a real
        // target user in the same tenant so the 4th request lands on the
        // limiter rather than tripping a 422.
        $target = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);
        $targetUserId = $target->id;

        RateLimiter::clear('verify-manager-pin:127.0.0.1:'.$targetUserId);

        Sanctum::actingAs($caller, ['tenant:'.$tenant->id, '*']);

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/v1/pos/verify-manager-pin', [
                'user_id' => $targetUserId,
                'pin' => '0000',
            ]);

            $this->assertNotEquals(
                429,
                $response->status(),
                "verify-manager-pin attempt #{$i} must not be throttled (limit is 3/30s); got 429 too early.",
            );
        }

        $response = $this->postJson('/api/v1/pos/verify-manager-pin', [
            'user_id' => $targetUserId,
            'pin' => '0000',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
    }
}
