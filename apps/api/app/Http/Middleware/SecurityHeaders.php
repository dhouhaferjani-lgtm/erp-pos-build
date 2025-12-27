<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to add security headers to all responses.
 *
 * These headers protect against:
 * - Clickjacking (X-Frame-Options)
 * - MIME type sniffing (X-Content-Type-Options)
 * - XSS attacks (X-XSS-Protection, CSP)
 * - Information leakage (Referrer-Policy)
 * - Downgrade attacks (Strict-Transport-Security in production)
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Prevent clickjacking by not allowing this site to be framed
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent browsers from MIME-sniffing a response away from declared content-type
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable XSS filter in browsers (legacy, but still useful for older browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Control how much referrer information is sent
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser features that can be used
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        // In production, enforce HTTPS and enable HSTS
        if (config('app.env') === 'production') {
            // max-age of 1 year, include subdomains, allow preloading
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
