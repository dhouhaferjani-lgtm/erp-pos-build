<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Shared\Domain\Enums\SkinType;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 14: verify that ParapharmacySeeder seeds ≥1 skin suitability row
 * per cosmetic product and that every skin_type value is a valid SkinType enum.
 */
final class ParapharmacySkinSuitabilitySeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_cosmetic_product_has_at_least_one_suitability_row(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $cosmeticProductIds = ParapharmacyProductMetadata::where('category', 'cosmetic')
            ->pluck('product_id');

        $this->assertGreaterThan(0, $cosmeticProductIds->count(), 'Expected cosmetic products after seeding');

        $coveredIds = DB::table('product_skin_suitability')
            ->whereIn('product_id', $cosmeticProductIds)
            ->distinct()
            ->pluck('product_id');

        $this->assertCount(
            $cosmeticProductIds->count(),
            $coveredIds,
            'Every cosmetic product must have at least one skin suitability row',
        );
    }

    public function test_all_skin_type_values_are_valid_enum_cases(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $validValues = array_column(SkinType::cases(), 'value');

        $invalidCount = DB::table('product_skin_suitability')
            ->whereNotIn('skin_type', $validValues)
            ->count();

        $this->assertSame(
            0,
            $invalidCount,
            'All skin_type values in product_skin_suitability must be valid SkinType enum values',
        );
    }

    public function test_no_duplicate_product_skin_type_pairs(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $duplicates = DB::table('product_skin_suitability')
            ->select('product_id', 'skin_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('product_id', 'skin_type')
            ->having('cnt', '>', 1)
            ->count();

        $this->assertSame(
            0,
            $duplicates,
            'The unique(product_id, skin_type) constraint must hold — no duplicate pairs',
        );
    }
}
