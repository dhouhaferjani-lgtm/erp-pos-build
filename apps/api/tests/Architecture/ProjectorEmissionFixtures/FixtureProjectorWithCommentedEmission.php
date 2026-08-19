<?php

declare(strict_types=1);

namespace Tests\Architecture\ProjectorEmissionFixtures;

/**
 * Fixture for ProjectorEmissionRatchetTest (M5 round 1, F-4).
 *
 * Every "emission" in this class is inside a comment or a doc-block. The
 * detector must read it as NOT emitting — before the F-4 fix, one such line was
 * enough to drop a projector out of the baseline and fire the message that tells
 * a maintainer to close its register row.
 *
 * A doc-block emission: event(new SomethingHappened());
 */
final class FixtureProjectorWithCommentedEmission
{
    public function apply(): void
    {
        // event(new SomethingHappened());
        // SomethingHappened::dispatch();
        /* $this->events->dispatch(new SomethingHappened()); */
    }
}
