<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

/**
 * Negative-control fixture for ControllerTenantContextTest.
 *
 * The handle() method intentionally has NO #[CrossTenantRoute] attribute
 * AND no heuristic substring in its body. The classifier MUST flag it as
 * unclassified. If a future change weakens the classifier to accept this
 * shape, test_classifier_pins_each_branch_of_the_invariant fails before
 * any production controller test masks the regression.
 *
 * Lives in tests/Architecture/ControllerFixtures/ and is excluded from
 * the universal scan via the discovery globs (which only scan
 * app/Http/Controllers/Api/** and app/Modules/**\/Presentation/Controllers/).
 */
final class FixtureUnclassifiedController
{
    public function handle(): array
    {
        $count = 0;

        return ['ok' => true, 'count' => $count];
    }
}
