<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 16: verify that ParapharmacySeeder seeds routines and ordered product memberships.
 *
 * Assertions:
 *  - ≥3 routines exist after seeding.
 *  - Each routine has 3–4 membership rows.
 *  - step_order within each routine is contiguous from 1.
 *  - No duplicate (routine_id, product_id) pair exists.
 */
final class ParapharmacyRoutinesSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_at_least_three_routines_are_seeded(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $count = DB::table('routines')->count();

        $this->assertGreaterThanOrEqual(3, $count, 'Expected at least 3 routines after seeding');
    }

    public function test_each_routine_has_between_three_and_four_membership_rows(): void
    {
        $this->seed(ParapharmacySeeder::class);

        /** @var list<string> $routineIds */
        $routineIds = DB::table('routines')->pluck('id')->toArray();

        $this->assertGreaterThanOrEqual(3, count($routineIds), 'Expected at least 3 routines');

        foreach ($routineIds as $routineId) {
            $count = DB::table('product_routine')->where('routine_id', $routineId)->count();

            $this->assertGreaterThanOrEqual(
                3,
                $count,
                "Routine {$routineId} must have at least 3 membership rows, got {$count}",
            );
            $this->assertLessThanOrEqual(
                4,
                $count,
                "Routine {$routineId} must have at most 4 membership rows, got {$count}",
            );
        }
    }

    public function test_step_order_is_contiguous_from_one_per_routine(): void
    {
        $this->seed(ParapharmacySeeder::class);

        /** @var list<string> $routineIds */
        $routineIds = DB::table('routines')->pluck('id')->toArray();

        foreach ($routineIds as $routineId) {
            /** @var list<int> $orders */
            $orders = DB::table('product_routine')
                ->where('routine_id', $routineId)
                ->orderBy('step_order')
                ->pluck('step_order')
                ->map(fn (mixed $v): int => (int) $v)
                ->toArray();

            $this->assertSame(
                range(1, count($orders)),
                $orders,
                "Routine {$routineId}: step_order must be contiguous from 1, got [".implode(',', $orders).']',
            );
        }
    }

    public function test_no_duplicate_product_per_routine(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $duplicates = DB::table('product_routine')
            ->select('routine_id', 'product_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('routine_id', 'product_id')
            ->having('cnt', '>', 1)
            ->count();

        $this->assertSame(
            0,
            $duplicates,
            'The unique(routine_id, product_id) constraint must hold — no duplicate pairs',
        );
    }
}
