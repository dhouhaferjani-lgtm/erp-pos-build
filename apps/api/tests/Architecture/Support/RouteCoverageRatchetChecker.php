<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final class RouteCoverageRatchetChecker
{
    /**
     * @param  list<string>  $baseline
     */
    public function check(RouteCoverageReport $report, array $baseline): RouteCoverageRatchetResult
    {
        $new = array_values(array_diff($report->uncoveredKeys(), $baseline));
        $stale = array_values(array_diff($baseline, $report->uncoveredKeys()));

        $messages = [];

        if ($new !== []) {
            $messages[] = 'These routes have no action gate and are not in the baseline: '.implode(', ', $new)
                .'. Add `can:<permission>` (declare the key in the owning module) — or `authz.self` PLUS an '
                .'entry in SelfServiceRouteRegistry::ALLOW_LIST if the route acts only on the caller\'s own '
                .'resources. NEW ROUTES CANNOT BE ADDED TO THE COVERAGE BASELINE.';
        }

        if ($stale !== []) {
            $messages[] = 'These baseline entries name routes that are now covered: '.implode(', ', $stale)
                .'. DELETE them from the baseline in the same commit that gated the route, and lower the '
                .'matching ceiling by the same amount.';
        }

        return new RouteCoverageRatchetResult($new, $stale, implode("\n", $messages));
    }
}
