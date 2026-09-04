<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Request hygiene Phase A, Task 10b — per-process dedupe for the log-only
 * lazy-loading guard (`AppServiceProvider::configureLazyLoadingGuard()`).
 *
 * The guard's handler fires once per violating ROW, so a single unconverted
 * N+1 over a 1000-row page emits 1000 identical `lazy-load` warnings into the
 * `stack` channel. The diagnostic value is entirely in the DISTINCT
 * (model class, relation) pairs — the row count adds nothing a reader can act
 * on — so this registry lets each pair through exactly once per PHP process
 * and counts the rest.
 *
 * Deliberately static, not injected: the guard handler is a static closure
 * registered at boot on the Model facade, outside any container scope, and the
 * state must survive every request/job the process serves.
 *
 * Allocation profile: a nested `array<string, array<string, true>>` keyed on
 * model class then relation — no per-violation string concatenation, no key
 * collision between pairs that would concatenate alike, and the whole set is
 * bounded by the number of distinct violating pairs in the codebase (tens),
 * not by traffic.
 *
 * No shutdown hook is registered. Emitting a `dedupe_suppressed` line at
 * process end would add a log write to EVERY FPM request and every artisan
 * run, including the overwhelming majority that saw no violation at all;
 * `suppressedCount()` is exposed instead for a caller that actually wants the
 * number.
 */
final class LazyLoadViolationLog
{
    /**
     * Distinct pairs already logged in this process.
     *
     * @var array<string, array<string, true>>
     */
    private static array $seen = [];

    private static int $suppressed = 0;

    /**
     * Claim the right to log this (model, relation) pair.
     *
     * @param  string  $model  Fully-qualified model class name.
     * @param  string  $relation  Relation method name.
     * @return bool True the FIRST time the pair is seen in this process, false
     *              on every repeat (which increments the suppressed counter).
     */
    public static function record(string $model, string $relation): bool
    {
        if (isset(self::$seen[$model][$relation])) {
            self::$suppressed++;

            return false;
        }

        self::$seen[$model][$relation] = true;

        return true;
    }

    /**
     * How many violations this process swallowed as duplicates.
     */
    public static function suppressedCount(): int
    {
        return self::$suppressed;
    }

    /**
     * Drop all state. Called from `Tests\TestCase::setUp()` so each test starts
     * with an empty set — without it the first test to trip a pair would eat
     * the log line every later test in the same phpunit process expects.
     */
    public static function reset(): void
    {
        self::$seen = [];
        self::$suppressed = 0;
    }
}
