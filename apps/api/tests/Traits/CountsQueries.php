<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Request hygiene Task 10 — restorable query counting.
 *
 * Uses only the connection's own query-log API, so nothing is left registered
 * after the sample: no `DB::listen` closure is attached (Laravel keeps those
 * for the life of the connection and they would leak into every later test in
 * the process).
 */
trait CountsQueries
{
    /**
     * Do not nest this helper. It flushes the connection's pre-existing query
     * log on entry and exit, so an inner call would destroy the outer sample.
     */
    protected function countQueries(callable $fn): int
    {
        $wasLogging = DB::connection()->logging();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $fn();

            return count(DB::getQueryLog());
        } finally {
            DB::flushQueryLog();
            if (! $wasLogging) {
                DB::disableQueryLog();
            }
        }
    }

    protected function assertQueryCountAtMost(int $max, callable $fn, string $message = ''): void
    {
        $actual = $this->countQueries($fn);
        self::assertLessThanOrEqual(
            $max,
            $actual,
            $message !== '' ? $message : 'Expected at most '.$max.' queries; ran '.$actual.'.',
        );
    }
}
