<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * The tombstone set, read from tests/Architecture/fixtures/route-tombstones.json.
 *
 * RouteCoverageClassifier consults THIS, and TombstoneRouteBehaviourTest PINS
 * each entry's closure source shape and proves it still returns an
 * unconditional 410 with no query on any configured connection. One file, two
 * consumers — which is the mechanical coupling gate r1 B-1 required, with the
 * proof gate r2 B-3 required: a classification that nothing verifies is a
 * claim, and a classification verified by a single invocation with a single
 * parameter set is a weaker claim than the exemption makes.
 */
final class TombstoneRouteRegistry
{
    public const FIXTURE_RELATIVE = 'tests/Architecture/fixtures/route-tombstones.json';

    /**
     * @return list<array{key: string, declared_at: string, error_code: string, parameters: array<string, string>, source: string}>
     */
    public static function entries(): array
    {
        $contents = file_get_contents(base_path(self::FIXTURE_RELATIVE));

        if (! is_string($contents)) {
            throw new \RuntimeException('Could not read '.self::FIXTURE_RELATIVE);
        }

        /** @var array{tombstones: list<array{key: string, declared_at: string, error_code: string, parameters: array<string, string>, source: string}>} $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['tombstones'];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['key'],
            self::entries(),
        );
    }
}
