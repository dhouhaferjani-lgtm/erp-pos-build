<?php

declare(strict_types=1);

namespace Tests\Unit\Uom;

use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use App\Modules\Uom\Domain\Exceptions\IncompatibleUnitsException;
use App\Modules\Uom\Domain\Services\UnitConversionService;
use Tests\TestCase;

class UnitConversionServiceTest extends TestCase
{
    private UnitConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnitConversionService();
    }

    /** @test */
    public function it_converts_kilograms_to_grams_correctly(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $g->exists = true;

        $result = $this->service->convert('2.5', $kg, $g);

        $this->assertSame('2500.00', $result);
    }

    /** @test */
    public function it_converts_grams_to_kilograms_correctly(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $g->exists = true;

        $result = $this->service->convert('2500', $g, $kg);

        $this->assertSame('2.50', $result);
    }

    /** @test */
    public function it_throws_exception_when_converting_incompatible_units(): void
    {
        $this->expectException(IncompatibleUnitsException::class);

        $weightCategory = new UnitCategory([
            'id' => 'cat-weight',
            'code' => 'weight',
        ]);
        $weightCategory->exists = true;

        $volumeCategory = new UnitCategory([
            'id' => 'cat-volume',
            'code' => 'volume',
        ]);
        $volumeCategory->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-weight',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $l = new Unit([
            'id' => 'unit-l',
            'category_id' => 'cat-volume',
            'code' => 'l',
            'name' => 'Liter',
            'symbol' => 'L',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $l->exists = true;

        $this->service->convert('10', $kg, $l);
    }

    /** @test */
    public function it_can_check_if_units_are_convertible(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $g->exists = true;

        $this->assertTrue($this->service->canConvert($kg, $g));
    }

    /** @test */
    public function it_returns_false_for_incompatible_units(): void
    {
        $weightCategory = new UnitCategory([
            'id' => 'cat-weight',
            'code' => 'weight',
        ]);
        $weightCategory->exists = true;

        $volumeCategory = new UnitCategory([
            'id' => 'cat-volume',
            'code' => 'volume',
        ]);
        $volumeCategory->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-weight',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $l = new Unit([
            'id' => 'unit-l',
            'category_id' => 'cat-volume',
            'code' => 'l',
            'name' => 'Liter',
            'symbol' => 'L',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $l->exists = true;

        $this->assertFalse($this->service->canConvert($kg, $l));
    }

    /** @test */
    public function it_respects_decimal_places_with_half_up_rounding(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $g->exists = true;

        $result = $this->service->convert('2.5678', $kg, $g);

        // 2.5678 kg = 2567.8 g → rounds to 2568 g (0 decimal places, half up)
        $this->assertSame('2568', $result);
    }

    /** @test */
    public function it_respects_floor_rounding_method(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'rounding_method' => RoundingMethod::Floor,
        ]);
        $g->exists = true;

        $result = $this->service->convert('2.5678', $kg, $g);

        // 2.5678 kg = 2567.8 g → floors to 2567 g (0 decimal places, floor)
        $this->assertSame('2567', $result);
    }

    /** @test */
    public function it_respects_ceil_rounding_method(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 0,
            'rounding_method' => RoundingMethod::Ceil,
        ]);
        $g->exists = true;

        $result = $this->service->convert('2.5678', $kg, $g);

        // 2.5678 kg = 2567.8 g → ceils to 2568 g (0 decimal places, ceil)
        $this->assertSame('2568', $result);
    }

    /** @test */
    public function it_gets_conversion_factor_between_compatible_units(): void
    {
        $category = new UnitCategory(['id' => 'cat-1']);
        $category->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-1',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $g = new Unit([
            'id' => 'unit-g',
            'category_id' => 'cat-1',
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $g->exists = true;

        $factor = $this->service->getConversionFactor($kg, $g);

        // 1 kg = 1000 g, so factor is 1000/1 = 1000
        $this->assertSame('1000.0000000000', $factor);
    }

    /** @test */
    public function it_returns_zero_conversion_factor_for_incompatible_units(): void
    {
        $weightCategory = new UnitCategory([
            'id' => 'cat-weight',
            'code' => 'weight',
        ]);
        $weightCategory->exists = true;

        $volumeCategory = new UnitCategory([
            'id' => 'cat-volume',
            'code' => 'volume',
        ]);
        $volumeCategory->exists = true;

        $kg = new Unit([
            'id' => 'unit-kg',
            'category_id' => 'cat-weight',
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $kg->exists = true;

        $l = new Unit([
            'id' => 'unit-l',
            'category_id' => 'cat-volume',
            'code' => 'l',
            'name' => 'Liter',
            'symbol' => 'L',
            'conversion_factor' => '1000',
            'decimal_places' => 2,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
        $l->exists = true;

        $factor = $this->service->getConversionFactor($kg, $l);

        $this->assertSame('0', $factor);
    }
}
