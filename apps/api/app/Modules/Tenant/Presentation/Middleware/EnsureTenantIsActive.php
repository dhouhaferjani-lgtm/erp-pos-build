<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Presentation\Middleware;

use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = tenant();

        if ($tenant === null) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_NOT_FOUND',
                    'message' => 'No tenant context found for this request.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_INACTIVE',
                    'message' => 'This tenant account is not active. Please contact support.',
                    'details' => [
                        'status' => $tenant->status->value,
                    ],
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 403);
        }

        if (! $tenant->hasValidSubscription()) {
            return response()->json([
                'error' => [
                    'code' => 'SUBSCRIPTION_EXPIRED',
                    'message' => 'Your subscription has expired. Please renew to continue.',
                    'details' => [
                        'plan' => $tenant->plan->value,
                        'trial_ends_at' => $tenant->trial_ends_at?->format('c'),
                        'subscription_ends_at' => $tenant->subscription_ends_at?->format('c'),
                    ],
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 402);
        }

        return $next($request);
    }
}
