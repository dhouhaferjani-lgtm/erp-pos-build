<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\Enums\EquivalenceType;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 15: verify that ParapharmacySeeder seeds product_equivalents
 * (both directions, same type) and product_complements (cross-category bundles).
 */
final class ParapharmacyEquivalentsAndComplementsSeedTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Equivalents ====================

    public function test_equivalent_pairs_are_stored_in_both_directions(): void
    {
        $this->seed(ParapharmacySeeder::class);

        // For every A→B row there must be a matching B→A row with the same type.
        $forward = DB::table('product_equivalents')
            ->select('product_id', 'equivalent_product_id', 'equivalence_type')
            ->get();

        $this->assertGreaterThan(0, $forward->count(), 'Expected at least one equivalent row after seeding');

        foreach ($forward as $row) {
            $reverse = DB::table('product_equivalents')
                ->where('product_id', $row->equivalent_product_id)
                ->where('equivalent_product_id', $row->product_id)
                ->where('equivalence_type', $row->equivalence_type)
                ->exists();

            $this->assertTrue(
                $reverse,
                "Missing reverse row for ({$row->product_id}, {$row->equivalent_product_id}) with type {$row->equivalence_type}",
            );
        }
    }

    public function test_equivalence_types_are_valid_enum_values(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $validValues = array_column(EquivalenceType::cases(), 'value');

        $invalidCount = DB::table('product_equivalents')
            ->whereNotIn('equivalence_type', $validValues)
            ->count();

        $this->assertSame(
            0,
            $invalidCount,
            'All equivalence_type values must be valid EquivalenceType enum values',
        );
    }

    public function test_no_self_referencing_equivalent_rows(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $selfRefs = DB::table('product_equivalents')
            ->whereColumn('product_id', 'equivalent_product_id')
            ->count();

        $this->assertSame(0, $selfRefs, 'No product_equivalents row may have product_id = equivalent_product_id');
    }

    // ==================== Complements ====================

    public function test_complement_rows_exist_after_seeding(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $count = DB::table('product_complements')->count();

        $this->assertGreaterThan(0, $count, 'Expected at least one complement row after seeding');
    }

    public function test_complements_cross_categories(): void
    {
        $this->seed(ParapharmacySeeder::class);

        // Each complement must link products from different categories.
        $crossCategory = DB::table('product_complements as pc')
            ->join('parapharmacy_product_metadata as ma', 'ma.product_id', '=', 'pc.product_id')
            ->join('parapharmacy_product_metadata as mb', 'mb.product_id', '=', 'pc.complement_product_id')
            ->whereColumn('ma.category', '!=', 'mb.category')
            ->count();

        $total = DB::table('product_complements')->count();

        $this->assertGreaterThan(0, $total, 'Expected complement rows');
        $this->assertSame(
            $total,
            $crossCategory,
            'All complement pairs must link products from different parapharmacy categories',
        );
    }

    public function test_no_self_referencing_complement_rows(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $selfRefs = DB::table('product_complements')
            ->whereColumn('product_id', 'complement_product_id')
            ->count();

        $this->assertSame(0, $selfRefs, 'No product_complements row may have product_id = complement_product_id');
    }
}
