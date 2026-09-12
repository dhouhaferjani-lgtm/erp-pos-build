<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Routing\Route;

/**
 * Classifies one live route. The SOURCE OF TRUTH is the router, not a grep: the
 * ratchet boots the application and reads Route::getRoutes(), so a route added
 * by any mechanism is seen.
 */
final class RouteCoverageClassifier
{
    /**
     * The complete gating set. A middleware outside this list is NOT coverage,
     * however protective it looks.
     *
     * The worked example this rule exists for arrives in wave 0b:
     * `lane/w-lot-a-1a` attaches `BatchActionAccess:batches.delete` and
     * `:batches.recall` to two batch writes, but that middleware returns
     * `$next($request)` with NO permission check at all while
     * `LotActionPermissionActivation::enforced()` is false — and false is the
     * shipped default. Counting a flag-conditional middleware as coverage would
     * mark two live, permission-free writes as gated on every tenant that has
     * not flipped the flag.
     *
     * @var list<string>
     */
    public const GATING_ALIASES = [
        'can',
        'require.any.permission',
        'super_admin',
        'central_admin',
        'central_admin_role',
    ];

    /**
     * The 18 routes that are public BY DESIGN, taken from the PUBLIC rows of
     * docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv.
     *
     * @var list<string>
     */
    public const PUBLIC_ALLOW_LIST = [
        'POST api/v1/auth/login',
        'POST api/v1/auth/register',
        'POST api/v1/auth/verify-email',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        'POST api/v1/admin/auth/login',
        'GET api/v1/health',
        'GET api/v1/countries',
        'GET api/v1/countries/{code}',
        'GET api/v1/storefront/{company_id}/availability',
        'POST api/v1/storefront/{company_id}/appointments',
        'GET api/v1/media/{tenant}/{attachment}/serve',
        'GET api/v1/public/products/{product}/images',
        'GET api/v1/public/products/{product}/images/{image}',
        'POST api/v1/webhooks/syneriva',
        'POST api/v1/webhooks/stripe',
        'POST api/v1/webhooks/channels/{channelId}',
        'POST api/webhooks/purchase-hub',
    ];

    /**
     * The tombstone set is NOT a constant here (gate r1 B-1). It is read from
     * tests/Architecture/fixtures/route-tombstones.json through
     * TombstoneRouteRegistry, and TombstoneRouteBehaviourTest proves every entry
     * in that file still returns an unconditional 410 with zero database
     * queries. A classification that nothing verifies is a claim, not a
     * classification: rev 1 of this plan would have kept a tombstone turned back
     * into a live mutation classified as covered.
     *
     * @return list<string>
     */
    public function tombstoneKeys(): array
    {
        return TombstoneRouteRegistry::keys();
    }

    public function classify(Route $route): RouteCoverage
    {
        $key = SelfServiceRouteRegistry::routeKey($route);

        if (in_array($key, $this->tombstoneKeys(), true)) {
            return RouteCoverage::Tombstone;
        }

        if (in_array($key, self::PUBLIC_ALLOW_LIST, true)) {
            return RouteCoverage::Open;
        }

        // Deliberately the ALLOW-LIST, never the middleware stack: a route
        // wearing `authz.self` with no entry there falls through to Uncovered.
        if (in_array($key, SelfServiceRouteRegistry::ALLOW_LIST, true)) {
            return RouteCoverage::SelfService;
        }

        if ($this->carriesGatingMiddleware($route)) {
            return RouteCoverage::Gated;
        }

        return RouteCoverage::Uncovered;
    }

    public function isWrite(Route $route): bool
    {
        $methods = SelfServiceRouteRegistry::normalizeMethods($route);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            if (str_contains($methods, $verb)) {
                return true;
            }
        }

        return false;
    }

    private function carriesGatingMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $alias = str_contains($middleware, ':')
                ? substr($middleware, 0, (int) strpos($middleware, ':'))
                : $middleware;

            if (in_array($alias, self::GATING_ALIASES, true)) {
                return true;
            }
        }

        return false;
    }
}
