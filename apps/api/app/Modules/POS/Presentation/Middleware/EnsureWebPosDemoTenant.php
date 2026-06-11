<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Middleware;

use App\Modules\Tenant\Domain\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web POS is demo-account-only (owner decision 2026-06-11 — see
 * docs/superpowers/tickets/2026-06-11-web-pos-demo-only-gate.md).
 *
 * Device-authority (v3) is the locked fiscal model: the Tauri terminal
 * authors shifts/receipts/Z locally and mirrors them to these same server
 * routes. The browser POS has no device chain, and its shift lifecycle
 * (open tied to one store, re-tie to another, forget to close) is
 * complexity we explicitly refuse to handle for real tenants.
 *
 * Rule:
 *  - Tauri device callers pass: every request from the POS app carries
 *    `X-Client-Type: pos-tauri` (apps/pos/src/lib/api.ts — same marker the
 *    AuthController device-bound login flow keys on). The threat model is
 *    operational misuse by legitimate web users, not header forgery — a
 *    forger gains nothing beyond the gated UI under their own auth.
 *  - Browser callers require `tenants.is_demo` (central directory flag,
 *    default false) → otherwise 403 WEB_POS_DEMO_ONLY.
 */
class EnsureWebPosDemoTenant
{
    private const POS_CLIENT_TYPE_HEADER = 'X-Client-Type';

    private const POS_CLIENT_TYPE_DEVICE = 'pos-tauri';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header(self::POS_CLIENT_TYPE_HEADER) === self::POS_CLIENT_TYPE_DEVICE) {
            return $next($request);
        }

        // Resolve the tenant from the initialized tenancy when present,
        // falling back to the authenticated user's tenant_id (the central
        // directory row) — auth:sanctum runs before this middleware.
        /** @var Tenant|null $tenant */
        $tenant = tenant();
        if ($tenant === null) {
            $user = $request->user();
            $tenantId = $user?->getAttribute('tenant_id');
            if (is_string($tenantId)) {
                $tenant = Tenant::find($tenantId);
            }
        }

        if ($tenant !== null && $tenant->is_demo === true) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'WEB_POS_DEMO_ONLY',
                'message' => 'The web POS is available to demo accounts only. Use the POS terminal application.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 403);
    }
}
