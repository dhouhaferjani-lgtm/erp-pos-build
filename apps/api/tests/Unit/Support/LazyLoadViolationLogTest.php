<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LazyLoadViolationLog;
use PHPUnit\Framework\TestCase;

/**
 * Request hygiene Task 10b — the per-process dedupe registry behind the
 * log-only lazy-loading guard.
 */
class LazyLoadViolationLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LazyLoadViolationLog::reset();
    }

    protected function tearDown(): void
    {
        LazyLoadViolationLog::reset();

        parent::tearDown();
    }

    public function test_first_sighting_of_a_pair_is_recorded(): void
    {
        self::assertTrue(LazyLoadViolationLog::record('App\\Models\\Widget', 'parts'));
        self::assertSame(0, LazyLoadViolationLog::suppressedCount());
    }

    public function test_repeat_sighting_of_the_same_pair_is_suppressed_and_counted(): void
    {
        LazyLoadViolationLog::record('App\\Models\\Widget', 'parts');

        self::assertFalse(LazyLoadViolationLog::record('App\\Models\\Widget', 'parts'));
        self::assertFalse(LazyLoadViolationLog::record('App\\Models\\Widget', 'parts'));
        self::assertSame(2, LazyLoadViolationLog::suppressedCount());
    }

    public function test_a_different_relation_on_the_same_model_is_recorded(): void
    {
        LazyLoadViolationLog::record('App\\Models\\Widget', 'parts');

        self::assertTrue(LazyLoadViolationLog::record('App\\Models\\Widget', 'owner'));
        self::assertSame(0, LazyLoadViolationLog::suppressedCount());
    }

    public function test_the_same_relation_on_a_different_model_is_recorded(): void
    {
        LazyLoadViolationLog::record('App\\Models\\Widget', 'parts');

        self::assertTrue(LazyLoadViolationLog::record('App\\Models\\Gadget', 'parts'));
    }

    /**
     * The key is built from the pair, not from a concatenation that two
     * different pairs could collide on.
     */
    public function test_pairs_that_concatenate_alike_do_not_collide(): void
    {
        self::assertTrue(LazyLoadViolationLog::record('A::B', 'C'));
        self::assertTrue(LazyLoadViolationLog::record('A', 'B::C'));
    }

    public function test_reset_clears_both_the_set_and_the_counter(): void
    {
        LazyLoadViolationLog::record('App\\Models\\Widget', 'parts');
        LazyLoadViolationLog::record('App\\Models\\Widget', 'parts');
        self::assertSame(1, LazyLoadViolationLog::suppressedCount());

        LazyLoadViolationLog::reset();

        self::assertSame(0, LazyLoadViolationLog::suppressedCount());
        self::assertTrue(LazyLoadViolationLog::record('App\\Models\\Widget', 'parts'));
    }
}
