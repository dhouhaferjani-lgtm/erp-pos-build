<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\SelfServiceRouteRegistry;
use Tests\TestCase;

/**
 * Set equality, in BOTH directions, between the routes wearing `authz.self`
 * and SelfServiceRouteRegistry::ALLOW_LIST.
 *
 *  (a) a route carrying the marker that is not on the list FAILS — the marker
 *      cannot certify itself;
 *  (b) a list entry that has lost the marker FAILS — the list cannot describe
 *      a route that stopped being self-service.
 */
final class SelfServiceRouteAllowListTest extends TestCase
{
    private const MARKER = 'authz.self';

    #[Test]
    public function every_allow_listed_route_carries_the_marker(): void
    {
        $marked = $this->markedRouteKeys();

        $missing = array_values(array_diff(SelfServiceRouteRegistry::ALLOW_LIST, $marked));

        self::assertSame(
            [],
            $missing,
            'Allow-listed routes that do NOT carry the `authz.self` middleware: '
            .implode(', ', $missing)
            .'. Either apply the marker in the owning routes.php, or delete the entry from '
            .'SelfServiceRouteRegistry::ALLOW_LIST.',
        );
    }

    #[Test]
    public function no_route_wears_the_marker_without_an_allow_list_entry(): void
    {
        $marked = $this->markedRouteKeys();

        $unlisted = array_values(array_diff($marked, SelfServiceRouteRegistry::ALLOW_LIST));

        self::assertSame(
            [],
            $unlisted,
            'Routes carrying `authz.self` with NO entry in SelfServiceRouteRegistry::ALLOW_LIST: '
            .implode(', ', $unlisted)
            .'. The marker is a declaration, not a gate: the ratchet classifies these routes as '
            .'UNCOVERED until the allow-list is edited in a reviewed diff.',
        );
    }

    #[Test]
    public function the_allow_list_holds_exactly_seven_routes_and_no_duplicates(): void
    {
        self::assertCount(7, SelfServiceRouteRegistry::ALLOW_LIST);
        self::assertSame(
            SelfServiceRouteRegistry::ALLOW_LIST,
            array_values(array_unique(SelfServiceRouteRegistry::ALLOW_LIST)),
        );
    }

    /**
     * @return list<string>
     */
    private function markedRouteKeys(): array
    {
        $keys = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }
                if ($middleware === self::MARKER || str_starts_with($middleware, self::MARKER.':')) {
                    $keys[] = SelfServiceRouteRegistry::routeKey($route);

                    break;
                }
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
    }
}
