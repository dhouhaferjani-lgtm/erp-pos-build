<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Routing\Route;

/**
 * THE DETECTOR, as a callable object (convention 08's shape, following
 * TenantOnlyUniqueIndexScanner in this same directory).
 *
 * It classifies the routes it is GIVEN. RoutePermissionCoverageRatchetTest hands
 * it the live router; RoutePermissionCoverageRatchetLivenessTest hands it
 * hand-built routes carrying a planted violation. Neither can be satisfied by
 * the other's data, which is what makes the tamper cases real: they prove the
 * guard FIRES, not merely that a fixture route exists.
 */
final class RoutePermissionCoverageScanner
{
    /**
     * THE UNIVERSE (gate r2 B-1). The enforcement standard is scoped to
     * `apps/api/routes/api.php` and the module `routes.php` files (spec 4.4.1
     * E-1), every one of which registers under this prefix. The live router
     * ALSO carries 38 framework routes — `web`, Horizon, Telescope, Scramble,
     * Debugbar — that no permission catalogue governs and that the audit's
     * 1054-row dataset never contained. Scanning them would report 353
     * uncovered routes instead of 315 and make this wave's own baseline command
     * unreachable, so they are skipped BEFORE classification, not classified and
     * then ignored.
     */
    public const API_URI_PREFIX = 'api/';

    public function __construct(
        private readonly RouteCoverageClassifier $classifier = new RouteCoverageClassifier,
    ) {}

    /**
     * @param  iterable<Route>  $routes
     */
    public function scan(iterable $routes): RouteCoverageReport
    {
        $keys = [];
        $writes = 0;
        $reads = 0;
        $counts = [
            RouteCoverage::Gated->value => 0,
            RouteCoverage::Open->value => 0,
            RouteCoverage::SelfService->value => 0,
            RouteCoverage::Tombstone->value => 0,
            RouteCoverage::Uncovered->value => 0,
        ];

        foreach ($routes as $route) {
            if (! $this->isInApiUniverse($route)) {
                continue;
            }

            $classification = $this->classifier->classify($route);
            $counts[$classification->value]++;

            if ($classification !== RouteCoverage::Uncovered) {
                continue;
            }

            $keys[] = SelfServiceRouteRegistry::routeKey($route);
            $this->classifier->isWrite($route) ? $writes++ : $reads++;
        }

        sort($keys);

        return new RouteCoverageReport(array_values(array_unique($keys)), $writes, $reads, $counts);
    }

    /**
     * Public because the liveness test asserts on it directly: a guard whose
     * scope rule is only reachable through a full scan cannot be tamper-tested
     * cheaply.
     */
    public function isInApiUniverse(Route $route): bool
    {
        return str_starts_with(ltrim($route->uri(), '/'), self::API_URI_PREFIX);
    }
}
