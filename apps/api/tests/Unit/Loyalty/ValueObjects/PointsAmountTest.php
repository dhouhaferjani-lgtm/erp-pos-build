<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\ValueObjects;

use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PointsAmountTest extends TestCase
{
    public function test_creates_points_amount_with_valid_value(): void
    {
        $points = new PointsAmount('100.50');

        // Canonicalised to scale 3.
        $this->assertSame('100.500', $points->value);
    }

    public function test_throws_exception_for_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount cannot be negative');

        new PointsAmount('-10');
    }

    public function test_throws_exception_for_value_exceeding_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount exceeds maximum allowed value');

        new PointsAmount('1000000000'); // Over 999,999,999.999
    }

    public function test_throws_exception_for_non_numeric_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount must be a numeric string');

        new PointsAmount('not-a-number');
    }

    public function test_from_numeric_creates_from_integer(): void
    {
        $points = PointsAmount::fromNumeric(50);

        $this->assertSame('50.000', $points->value);
    }

    public function test_from_numeric_creates_from_float(): void
    {
        $points = PointsAmount::fromNumeric(75.25);

        $this->assertSame('75.250', $points->value);
    }

    public function test_from_numeric_string_creates_from_string(): void
    {
        $points = PointsAmount::fromNumericString('75.25');

        $this->assertSame('75.250', $points->value);
    }

    public function test_zero_creates_zero_points(): void
    {
        $points = PointsAmount::zero();

        $this->assertSame('0.000', $points->value);
    }

    public function test_one_creates_one_point(): void
    {
        $points = PointsAmount::one();

        $this->assertSame('1.000', $points->value);
    }

    public function test_add_returns_new_instance_with_sum(): void
    {
        $points1 = new PointsAmount('50');
        $points2 = new PointsAmount('30');

        $result = $points1->add($points2);

        $this->assertSame('80.000', $result->value);
        $this->assertSame('50.000', $points1->value); // Original unchanged
    }

    public function test_subtract_returns_new_instance_with_difference(): void
    {
        $points1 = new PointsAmount('100');
        $points2 = new PointsAmount('30');

        $result = $points1->subtract($points2);

        $this->assertSame('70.000', $result->value);
        $this->assertSame('100.000', $points1->value); // Original unchanged
    }

    public function test_subtract_throws_exception_when_result_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot subtract more points than available');

        (new PointsAmount('30'))->subtract(new PointsAmount('50'));
    }

    public function test_multiply_returns_new_instance_with_product(): void
    {
        $points = new PointsAmount('50');

        $result = $points->multiply('2.5');

        $this->assertSame('125.000', $result->value);
    }

    public function test_multiply_throws_exception_for_negative_multiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier cannot be negative');

        (new PointsAmount('50'))->multiply('-2.0');
    }

    public function test_percentage_calculates_percentage_of_points(): void
    {
        $points = new PointsAmount('200');

        $result = $points->percentage('25');

        $this->assertSame('50.000', $result->value); // 25% of 200
    }

    public function test_percentage_throws_exception_for_invalid_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Percentage must be between 0 and 100');

        (new PointsAmount('100'))->percentage('150');
    }

    public function test_round_rounds_to_nearest_whole_number(): void
    {
        $result = (new PointsAmount('50.6'))->round();

        $this->assertSame('51.000', $result->value);
    }

    public function test_ceil_rounds_up_to_whole_number(): void
    {
        $result = (new PointsAmount('50.1'))->ceil();

        $this->assertSame('51.000', $result->value);
    }

    public function test_floor_rounds_down_to_whole_number(): void
    {
        $result = (new PointsAmount('50.9'))->floor();

        $this->assertSame('50.000', $result->value);
    }

    public function test_min_returns_smaller_of_two_amounts(): void
    {
        $result = (new PointsAmount('100'))->min(new PointsAmount('75'));

        $this->assertSame('75.000', $result->value);
    }

    public function test_max_returns_larger_of_two_amounts(): void
    {
        $result = (new PointsAmount('100'))->max(new PointsAmount('75'));

        $this->assertSame('100.000', $result->value);
    }

    public function test_is_zero_returns_true_for_zero(): void
    {
        $this->assertTrue(PointsAmount::zero()->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero(): void
    {
        $this->assertFalse((new PointsAmount('0.5'))->isZero());
    }

    public function test_is_greater_than_compares_correctly(): void
    {
        $points1 = new PointsAmount('100');
        $points2 = new PointsAmount('50');

        $this->assertTrue($points1->isGreaterThan($points2));
        $this->assertFalse($points2->isGreaterThan($points1));
    }

    public function test_is_less_than_compares_correctly(): void
    {
        $points1 = new PointsAmount('50');
        $points2 = new PointsAmount('100');

        $this->assertTrue($points1->isLessThan($points2));
        $this->assertFalse($points2->isLessThan($points1));
    }

    public function test_equals_returns_true_for_equal_amounts(): void
    {
        $this->assertTrue((new PointsAmount('100'))->equals(new PointsAmount('100')));
    }

    public function test_equals_returns_false_for_different_amounts(): void
    {
        $this->assertFalse((new PointsAmount('100'))->equals(new PointsAmount('50')));
    }

    public function test_format_formats_with_specified_decimals(): void
    {
        $points = new PointsAmount('1234.56');

        $this->assertEquals('1,234.56', $points->format(2));
    }

    public function test_to_string_returns_canonical_string_representation(): void
    {
        $this->assertSame('100.500', (new PointsAmount('100.5'))->toString());
    }

    public function test_to_int_returns_rounded_integer(): void
    {
        $this->assertSame(101, (new PointsAmount('100.7'))->toInt());
    }

    public function test_value_object_is_immutable(): void
    {
        $original = new PointsAmount('100');
        $result = $original->add(new PointsAmount('50'));

        $this->assertSame('100.000', $original->value);
        $this->assertSame('150.000', $result->value);
        $this->assertNotSame($original, $result);
    }
}
