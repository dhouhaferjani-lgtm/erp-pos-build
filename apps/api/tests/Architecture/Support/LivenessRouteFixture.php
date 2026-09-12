<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * The reader over tests/Architecture/fixtures/route-permission-coverage-liveness.json.
 *
 * Same shape as TombstoneRouteRegistry: one file, decoded once, two consumers —
 * LivenessRouteServiceProvider (which registers the routes before the router
 * boots) and RoutePermissionCoverageRatchetLivenessTest (which asserts the exact
 * classification and universe membership each record declares).
 */
final class LivenessRouteFixture
{
    public const FIXTURE_RELATIVE = 'tests/Architecture/fixtures/route-permission-coverage-liveness.json';

    /**
     * @return list<array{method: string, uri: string, middleware: list<string>, expected_classification: string, in_api_universe: bool, why: string}>
     */
    public static function records(): array
    {
        $contents = file_get_contents(base_path(self::FIXTURE_RELATIVE));

        if (! is_string($contents)) {
            throw new \RuntimeException('Could not read '.self::FIXTURE_RELATIVE);
        }

        /** @var array{routes: list<array{method: string, uri: string, middleware: list<string>, expected_classification: string, in_api_universe: bool, why: string}>} $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['routes'];
    }
}
