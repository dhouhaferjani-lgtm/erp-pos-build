<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Shared\Domain\Enums\SkinType;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 17: verify that ParapharmacySeeder seeds a SkinType for ≥80% of
 * individual (customer-type) demo partners and that every assigned
 * skin_type value is a valid SkinType enum case.
 */
final class ParapharmacyCustomerSkinTypesSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_at_least_80_percent_of_customer_partners_have_skin_type(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $total = DB::table('partners')->where('type', 'customer')->count();
        $this->assertGreaterThan(0, $total, 'Expected customer partners after seeding');

        $withSkinType = DB::table('partners')
            ->where('type', 'customer')
            ->whereNotNull('skin_type')
            ->count();

        $coverage = $withSkinType / $total;
        $this->assertGreaterThanOrEqual(
            0.80,
            $coverage,
            "At least 80% of customer partners must have a non-null skin_type (got {$withSkinType}/{$total})",
        );
    }

    public function test_all_assigned_skin_type_values_are_valid_enum_cases(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $validValues = array_column(SkinType::cases(), 'value');

        $invalidCount = DB::table('partners')
            ->where('type', 'customer')
            ->whereNotNull('skin_type')
            ->whereNotIn('skin_type', $validValues)
            ->count();

        $this->assertSame(
            0,
            $invalidCount,
            'All skin_type values assigned to customer partners must be valid SkinType enum values',
        );
    }
}
