<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to add security headers to all responses.
 *
 * Headers added:
 * - X-Frame-Options (clickjacking)
 * - X-Content-Type-Options (MIME sniffing)
 * - X-XSS-Protection (legacy XSS filter)
 * - Referrer-Policy (referrer leakage)
 * - Permissions-Policy (browser feature gating)
 * - Strict-Transport-Security (production only)
 * - Content-Security-Policy (route-aware: strict on API, permissive on web)
 *
 * dev-remediation/D — added route-aware CSP per the master plan §M2.6:
 * API responses use the strict `default-src 'none'` policy; web
 * responses use a permissive policy enumerating the origins the SPA
 * actually depends on (self assets, telemetry, websocket, public images).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        if (config('app.env') === 'production') {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        $response->headers->set('Content-Security-Policy', $this->resolveCsp($request));

        return $response;
    }

    /**
     * Build the route-aware Content-Security-Policy. API routes ship a
     * strict `default-src 'none'` policy since they only emit JSON; web
     * routes ship a policy that permits the SPA's own assets plus the
     * connect/img/font origins the SPA actually uses.
     */
    private function resolveCsp(Request $request): string
    {
        if ($this->isApiRequest($request)) {
            return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'";
        }

        // Web SPA policy. Tightened from default-src 'self' so it:
        // - allows the SPA's own JS/CSS bundles
        // - permits inline styles (Tailwind utility classes inject inline)
        // - permits images/fonts from self + data: + https: (catalog
        //   images may live on a CDN configured at deploy time)
        // - permits same-origin + https: + wss: for API + Reverb
        // - blocks framing, base-uri redirection, and object-src.
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "connect-src 'self' https: wss:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            "form-action 'self'",
        ]);
    }

    private function isApiRequest(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'api/') || str_starts_with($path, 'v1/');
    }
}
