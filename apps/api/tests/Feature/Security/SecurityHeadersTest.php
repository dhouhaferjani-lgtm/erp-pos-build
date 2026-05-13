<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies that SecurityHeaders middleware emits the required headers
 * for every response, with a route-aware Content-Security-Policy that
 * is strict on api/* routes and permissive on web routes.
 *
 * dev-remediation/D — master plan §M2.6.
 */
final class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mount two probe routes so we can exercise both branches of
        // the route-aware CSP without depending on the real API surface.
        Route::middleware('api')->get('/api/v1/_test/security-headers', fn () => response()->json(['ok' => true]));
        Route::middleware('api')->get('/_test/security-headers-web', fn () => response()->json(['ok' => true]));
    }

    public function test_api_route_emits_strict_csp(): void
    {
        $response = $this->getJson('/api/v1/_test/security-headers');

        $response->assertStatus(200);
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'Content-Security-Policy header must be emitted on API routes.');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
    }

    public function test_web_route_emits_permissive_csp(): void
    {
        $response = $this->getJson('/_test/security-headers-web');

        $response->assertStatus(200);
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("img-src 'self' data: blob: https:", $csp);
        $this->assertStringContainsString("connect-src 'self' https: wss:", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_baseline_security_headers_present_on_api_route(): void
    {
        $response = $this->getJson('/api/v1/_test/security-headers');

        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
    }

    public function test_hsts_present_only_in_production(): void
    {
        Config::set('app.env', 'local');
        $local = $this->getJson('/api/v1/_test/security-headers');
        $this->assertNull($local->headers->get('Strict-Transport-Security'));

        Config::set('app.env', 'production');
        $prod = $this->getJson('/api/v1/_test/security-headers');
        $hsts = $prod->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }
}
