<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * First-tenant production security gate (dev-remediation/M1.8).
 *
 * These tests enforce a minimum production posture for the first-tenant
 * pilot. They do NOT replace the full security review (M2.6 covers the
 * remaining headers/CSP work) — they catch the two regression classes
 * that would silently break the pilot:
 *
 *  - CORS configured to allow `*` origin WITH credentials in production.
 *    Per https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS, the
 *    browser refuses to send credentials when origin is `*`, but the
 *    config combination is itself a bug class (it implies the operator
 *    forgot to enumerate explicit origins). The fail-fast assertion
 *    surfaces the bug before deploy.
 *  - Missing rate-limiters on the auth surfaces the first tenant
 *    exposes. login / password-reset / register / manager-PIN /
 *    POS-terminal flows must each have a registered RateLimiter::for(...)
 *    handler so brute-force traffic gets HTTP 429 rather than silently
 *    consuming the auth surface.
 */
class FirstTenantProductionSecurityTest extends TestCase
{
    public function test_production_cors_guard_throws_on_wildcard_origin_with_credentials(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.supports_credentials', true);

        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe CORS configuration');

        $this->invokeCorsGuard();
    }

    public function test_production_cors_guard_allows_explicit_origins_with_credentials(): void
    {
        Config::set('cors.allowed_origins', ['https://app.example.com', 'https://admin.example.com']);
        Config::set('cors.supports_credentials', true);

        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->invokeCorsGuard();

        $this->assertTrue(true, 'Guard must allow the explicit-origin production configuration.');
    }

    public function test_non_production_cors_guard_allows_wildcard(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.supports_credentials', true);

        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->invokeCorsGuard();

        $this->assertTrue(true, 'Guard is production-only — local/dev/testing must continue to allow wildcard.');
    }

    private function invokeCorsGuard(): void
    {
        $provider = new AppServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'guardProductionCorsConfig');
        $method->invoke($provider);
    }

    /** @return array<string, array{0: string}> */
    public static function requiredRateLimiterProvider(): array
    {
        return [
            'login' => ['login'],
            'password-reset' => ['password-reset'],
            'register' => ['register'],
            'email-verification' => ['email-verification'],
            'api' => ['api'],
            // dev-remediation/D — M2.6 additional rate limiters.
            'document-email' => ['document-email'],
            'pos-terminal-activation' => ['pos-terminal-activation'],
            // F.3 — check-email enumeration mitigation.
            'check-email' => ['check-email'],
        ];
    }

    /**
     * @dataProvider requiredRateLimiterProvider
     */
    public function test_required_rate_limiter_is_registered(string $name): void
    {
        $resolver = RateLimiter::limiter($name);

        $this->assertNotNull(
            $resolver,
            "Required RateLimiter::for('{$name}') must be registered in AppServiceProvider so the "
            .'first-tenant auth surface receives HTTP 429 under abusive traffic.',
        );

        // The resolver returns a Limit instance (or array of limits); make sure
        // it does not silently return null (no-op) which would bypass throttling.
        $request = Request::create('/_test', 'POST');
        $request->setUserResolver(static fn () => null);

        $limit = $resolver($request);
        $this->assertNotNull(
            $limit,
            "RateLimiter::for('{$name}') must return a Limit (or array of limits), not null.",
        );

        $limits = is_array($limit) ? $limit : [$limit];
        foreach ($limits as $entry) {
            $this->assertInstanceOf(
                Limit::class,
                $entry,
                "RateLimiter::for('{$name}') resolver must return Limit instances.",
            );
        }
    }

    public function test_manager_pin_endpoint_enforces_target_context_rate_limit(): void
    {
        // The ManagerPinController uses a hand-rolled key
        // 'verify-manager-pin:<tenant_id>:<terminal_id>:<user_id>:<approval_scope>'
        // with 3 attempts per 30 seconds.
        // Verify the key shape and threshold are still in place so a brute
        // force attack on a single approval target gets HTTP 429 after the
        // third attempt, regardless of the network-wide login throttle.
        $controllerSource = file_get_contents(
            base_path('app/Modules/POS/Presentation/Controllers/ManagerPinController.php'),
        );

        $this->assertNotFalse($controllerSource);
        $this->assertStringContainsString(
            "'verify-manager-pin:'.\$caller->tenant_id.':'.\$terminalId.':'.\$userId.':'.\$approvalScope->value",
            $controllerSource,
            'ManagerPinController must keep the per approval target rate-limit key shape.',
        );
        $this->assertStringContainsString(
            'RateLimiter::tooManyAttempts($key, 3)',
            $controllerSource,
            'ManagerPinController must keep the 3-attempt threshold before issuing HTTP 429.',
        );
    }
}
