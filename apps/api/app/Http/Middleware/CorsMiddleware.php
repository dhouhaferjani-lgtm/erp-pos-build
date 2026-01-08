<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle preflight OPTIONS requests
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $request->header('Origin') ?? '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, X-Requested-With, X-XSRF-TOKEN, X-Company-Id, X-Location-Id')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400');
        }

        /** @var Response $response */
        $response = $next($request);

        // Add CORS headers to the response
        $response->headers->set('Access-Control-Allow-Origin', $request->header('Origin') ?? '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, X-Requested-With, X-XSRF-TOKEN, X-Company-Id, X-Location-Id');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
