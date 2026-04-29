<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\UomSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 — `unit_categories` partial-unique-index gap.
 *
 * PostgreSQL treats each NULL as distinct, so the original
 * `unique(['tenant_id', 'code'])` constraint allows duplicate system
 * rows (`tenant_id IS NULL`) with the same code. Re-running the UoM
 * seeder must NOT produce duplicate `(NULL, code)` system rows, and
 * any attempt to insert a duplicate system row must fail at the
 * database level.
 */
class UnitCategorySystemRowUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_re_running_uom_seeder_does_not_create_duplicate_system_rows(): void
    {
        // Arrange — first run plants the canonical 5 system categories.
        $this->seed(UomSeeder::class);

        // Strip child Units so the second seeder run can't blow up on FK
        // collisions or unique(code) on units; the constraint under test
        // is only on unit_categories.
        DB::table('units')->delete();
        DB::table('unit_categories')->update(['base_unit_id' => null]);

        // Act — try to seed again. Without the partial unique fix this
        // would silently create a second batch of (NULL, 'weight'),
        // (NULL, 'volume'), etc. system rows because PG treats each NULL
        // as distinct in a multi-column unique index.
        try {
            $this->seed(UomSeeder::class);
        } catch (QueryException) {
            // The fixed schema must reject the duplicate at the DB level.
            // Either swallowing or surfacing the error is acceptable here;
            // what matters is the assertion below — no duplicate rows.
        }

        // Assert — exactly one system row per code.
        $duplicates = DB::table('unit_categories')
            ->whereNull('tenant_id')
            ->select('code', DB::raw('COUNT(*) as cnt'))
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(
            0,
            $duplicates,
            'Re-running UomSeeder must not create duplicate (NULL tenant_id, code) rows. Found: '
                .$duplicates->pluck('code')->implode(', ')
        );

        // Spot-check the canonical codes are still present (one each).
        foreach (['weight', 'volume', 'length', 'pieces', 'time'] as $code) {
            $this->assertSame(
                1,
                UnitCategory::query()->whereNull('tenant_id')->where('code', $code)->count(),
                "Expected exactly one system row for code '{$code}'."
            );
        }
    }

    public function test_duplicate_system_row_insert_is_rejected_by_database(): void
    {
        // Arrange — one system row.
        UnitCategory::create([
            'code' => 'weight',
            'name' => 'Weight',
            'is_system' => true,
            'is_active' => true,
        ]);

        // Act + Assert — second insert with same (NULL, 'weight') must fail.
        $this->expectException(QueryException::class);

        UnitCategory::create([
            'code' => 'weight',
            'name' => 'Weight (duplicate)',
            'is_system' => true,
            'is_active' => true,
        ]);
    }
}
