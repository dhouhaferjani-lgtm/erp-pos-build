<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use App\Modules\Uom\Domain\Services\UnitConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Precision regression: UnitConversionService::round() must not cast
 * values to float (PHP float is 64-bit IEEE 754 — values above ~10^15
 * lose sub-unit precision).
 *
 * The old implementation:
 *   round((float) $scaled) / floor((float) $scaled) / ceil((float) $scaled)
 *
 * For extreme quantities like 99_999_999_999_999.9999 the (float) cast
 * silently corrupts the last digits and can produce scientific notation
 * ("1.0E+14") in the output string which bcmath cannot parse.
 *
 * The fix: delegate to QuantityScale::round() which stays in bcmath
 * throughout (no float intermediary, no scientific notation).
 */
final class UnitConversionPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private UnitConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnitConversionService;
    }

    /**
     * Convert an extreme quantity and assert:
     *  1. The result string contains NO scientific notation (no 'E' or 'e').
     *  2. The conversion arithmetic is correct to the expected scale.
     *
     * Scenario: 1:1 ratio (factor 1→1) so no scaling — result = input
     * rounded to toUnit.decimal_places.
     * Input: '99999999999999.9999', toUnit decimal_places = 4, HalfUp.
     * Expected result: '99999999999999.9999'  (no rounding needed — exactly 4dp)
     */
    public function test_convert_extreme_quantity_no_scientific_notation_half_up(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        /** @var Unit $from */
        $from = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'base',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::HalfUp,
            'is_base_unit' => true,
        ]);

        /** @var Unit $to */
        $to = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'same',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);

        $result = $this->service->convert('99999999999999.9999', $from, $to);

        // No scientific notation anywhere in the output.
        $this->assertStringNotContainsStringIgnoringCase('e', $result,
            'Result must not contain scientific notation: got '.$result);

        // Value must be preserved exactly (no rounding needed at 4dp).
        $this->assertSame('99999999999999.9999', $result);
    }

    /**
     * HalfUp rounding: .5 or above rounds up.
     * 1.55555 with 4 dp → '1.5556'
     */
    public function test_half_up_rounding_rounds_correctly(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        /** @var Unit $from */
        $from = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_a',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::HalfUp,
            'is_base_unit' => true,
        ]);

        /** @var Unit $to */
        $to = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_b',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);

        // 1.55555 → multiply by 10^4 = 15555.5 → HalfUp → 15556 → /10^4 = 1.5556
        $result = $this->service->convert('1.55555', $from, $to);
        $this->assertSame('1.5556', $result);
    }

    /**
     * Floor rounding: always round down.
     * 1.99999 with 4 dp → '1.9999'
     */
    public function test_floor_rounding_truncates_down(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        /** @var Unit $from */
        $from = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_c',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::Floor,
            'is_base_unit' => true,
        ]);

        /** @var Unit $to */
        $to = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_d',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::Floor,
        ]);

        // 1.99999 → multiply by 10^4 = 19999.9 → Floor → 19999 → /10^4 = 1.9999
        $result = $this->service->convert('1.99999', $from, $to);
        $this->assertSame('1.9999', $result);
    }

    /**
     * Ceil rounding: always round up.
     * 1.00001 with 4 dp → '1.0001'
     */
    public function test_ceil_rounding_rounds_up(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        /** @var Unit $from */
        $from = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_e',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::Ceil,
            'is_base_unit' => true,
        ]);

        /** @var Unit $to */
        $to = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'unit_f',
            'conversion_factor' => '1',
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::Ceil,
        ]);

        // 1.00001 → multiply by 10^4 = 10000.1 → Ceil → 10001 → /10^4 = 1.0001
        $result = $this->service->convert('1.00001', $from, $to);
        $this->assertSame('1.0001', $result);
    }
}
