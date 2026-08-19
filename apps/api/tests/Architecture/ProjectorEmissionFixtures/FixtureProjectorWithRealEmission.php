<?php

declare(strict_types=1);

namespace Tests\Architecture\ProjectorEmissionFixtures;

/**
 * Fixture for ProjectorEmissionRatchetTest (M5 round 1, F-4) — the positive
 * control. Stripping comments must not also strip real code: this class emits
 * for real and must be detected.
 */
final class FixtureProjectorWithRealEmission
{
    public function apply(): void
    {
        event(new \stdClass);
    }
}
