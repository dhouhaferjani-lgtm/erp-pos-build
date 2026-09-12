<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route as acting only on the authenticated principal's own resources.
 *
 * It is a DECLARATION, not a gate: it asserts authentication and records the
 * route as deliberately permission-free so the route-coverage ratchet
 * (Tests\Architecture\RoutePermissionCoverageRatchetTest) can distinguish
 * "no permission by design" from "no permission by accident".
 *
 * It performs NO ownership check, because ownership on the seven marked routes
 * is STRUCTURAL ($request->user()), not a lookup. A self-service route that
 * takes an id in the path and looks a record up is NOT eligible for this marker
 * and must carry a policy instead (spec 4.4.1 E-3).
 *
 * THE MARKER IS NOT SELF-CERTIFYING. Wearing it is never, by itself, coverage:
 * the ratchet classifies a route as self-service from the exact (method, uri)
 * allow-list in Tests\Architecture\Support\SelfServiceRouteRegistry, never from
 * the middleware stack, and a route wearing the marker with no entry there is
 * classified UNCOVERED. SelfServiceRouteAllowListTest asserts set equality in
 * both directions; SelfServiceRouteShapeTest asserts the URI shape.
 *
 * WAVE 2b adds the PrincipalKind::Service arm: a machine principal has no
 * session to end, so the logout family will answer 403
 * SELF_SERVICE_NOT_APPLICABLE. That arm cannot ship in wave 0a — the
 * users.principal_kind column does not exist until the wave-2b migration (spec
 * 5.1) — and its absence changes nothing today, because no service principal
 * can authenticate yet.
 */
final class AllowSelfService
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required for this action.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 401);
        }

        return $next($request);
    }
}
