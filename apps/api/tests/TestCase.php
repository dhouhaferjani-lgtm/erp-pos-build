<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\QuarantinedTests;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reset PHP's execution-time budget before every test.
     *
     * ImportController and a handful of other long-running paths call
     * `set_time_limit(300)` to give their own request more wall-clock budget
     * in production. When the test suite exercises those paths, the limit
     * sticks for the rest of the phpunit run and the entire suite aborts
     * once 300 cumulative seconds elapse. The tests/bootstrap.php +
     * phpunit.xml `max_execution_time=0` directives only cover the initial
     * boot — this setUp keeps each test starting from an unlimited budget
     * regardless of what previous tests did. M1.6 of the dev remediation
     * plan documents the gate.
     */
    protected function setUp(): void
    {
        set_time_limit(0);

        // O-29 first-execution quarantine. A no-op unless AUTOERP_QUARANTINE=1
        // (the eight Feature-lane jobs and scripts/run-feature-lane-local.sh set
        // it; nothing else does) AND this exact class/method is enumerated in
        // tests/quarantine.json. Deliberately BEFORE parent::setUp(): a target is
        // quarantined precisely because it cannot get through its own fixtures,
        // so booting the application first would fail before the skip.
        // See Tests\Support\QuarantinedTests for why the skip lives here rather
        // than in a phpunit flag on the lane's run line.
        $reason = QuarantinedTests::reasonFor(static::class, $this->name());
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }

        parent::setUp();
    }
}
