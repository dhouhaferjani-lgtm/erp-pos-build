<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Routing\Route;

/**
 * The authoritative, exact definition of "self-service" for the route-coverage
 * ratchet.
 *
 * `authz.self` is a DECLARATION, not a gate, and it is NOT self-certifying: the
 * alias is registered once in bootstrap/app.php and every later use is an
 * ordinary route edit, so a developer could otherwise satisfy the ratchet by
 * writing the marker on any ungated route — including one that takes another
 * principal's id. This class is the mechanical constraint that makes the
 * declaration reviewable: the ratchet classifies a route as self-service from
 * ALLOW_LIST, never from the middleware stack, and a route wearing the marker
 * without an entry here is classified UNCOVERED.
 */
final class SelfServiceRouteRegistry
{
    /**
     * The literal seven. Not a count, not a prefix. Adding a route means
     * editing this table, which is a diff a reviewer sees and a gate can
     * argue with.
     *
     * @var list<string>
     */
    public const ALLOW_LIST = [
        'GET api/v1/auth/me',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/logout-all',
        'POST api/v1/auth/resend-verification',
        'POST api/v1/notifications/read-all',
        'POST api/v1/notifications/{id}/read',
        'POST api/v1/support-access/sessions/{session}/exit',
    ];

    /**
     * URI parameters a marked route may carry: the caller's own session or
     * token id. Anything else names another resource and is refused by
     * SelfServiceRouteShapeTest even if it is on the allow-list.
     *
     * @var list<string>
     */
    public const SELF_ADDRESSABLE_PARAMETERS = ['session', 'tokenId'];

    /**
     * Named exemptions from the shape rule, each with its stated reason. This
     * is a named constant, not a hole: an entry here is as reviewable as an
     * ALLOW_LIST entry.
     *
     * @var array<string, string>
     */
    public const SHAPE_EXEMPTIONS = [
        'POST api/v1/notifications/{id}/read' => 'The notification is looked up scoped to $request->user(), so {id} selects among the '
            .'caller\'s own rows and cannot address another principal\'s.',
    ];

    public static function routeKey(Route $route): string
    {
        return self::normalizeMethods($route).' '.ltrim($route->uri(), '/');
    }

    /**
     * Laravel auto-registers HEAD alongside GET; the audit and this ratchet
     * both speak of a GET route, so HEAD is dropped when GET is present.
     */
    public static function normalizeMethods(Route $route): string
    {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => $method !== 'HEAD',
        ));

        if ($methods === []) {
            $methods = ['HEAD'];
        }

        return implode('|', $methods);
    }

    /**
     * @return list<string>
     */
    public static function parameterNames(string $uri): array
    {
        $matches = [];
        preg_match_all('/\{([^}?]+)\??}/', $uri, $matches);

        return $matches[1];
    }
}
