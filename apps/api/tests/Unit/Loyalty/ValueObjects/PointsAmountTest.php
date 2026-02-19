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
        $points = new PointsAmount(100.50);

        $this->assertEquals(100.50, $points->value);
    }

    public function test_throws_exception_for_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount cannot be negative');

        new PointsAmount(-10.0);
    }

    public function test_throws_exception_for_value_exceeding_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount exceeds maximum allowed value');

        new PointsAmount(1000000000.00); // Over 999,999,999.99
    }

    public function test_throws_exception_for_too_many_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount must have at most 2 decimal places');

        new PointsAmount(100.123); // More than 2 decimal places
    }

    public function test_from_numeric_creates_from_integer(): void
    {
        $points = PointsAmount::fromNumeric(50);

        $this->assertEquals(50.0, $points->value);
    }

    public function test_from_numeric_creates_from_float(): void
    {
        $points = PointsAmount::fromNumeric(75.25);

        $this->assertEquals(75.25, $points->value);
    }

    public function test_zero_creates_zero_points(): void
    {
        $points = PointsAmount::zero();

        $this->assertEquals(0.0, $points->value);
    }

    public function test_one_creates_one_point(): void
    {
        $points = PointsAmount::one();

        $this->assertEquals(1.0, $points->value);
    }

    public function test_add_returns_new_instance_with_sum(): void
    {
        $points1 = new PointsAmount(50.0);
        $points2 = new PointsAmount(30.0);

        $result = $points1->add($points2);

        $this->assertEquals(80.0, $result->value);
        $this->assertEquals(50.0, $points1->value); // Original unchanged
    }

    public function test_subtract_returns_new_instance_with_difference(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(30.0);

        $result = $points1->subtract($points2);

        $this->assertEquals(70.0, $result->value);
        $this->assertEquals(100.0, $points1->value); // Original unchanged
    }

    public function test_subtract_throws_exception_when_result_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot subtract more points than available');

        $points1 = new PointsAmount(30.0);
        $points2 = new PointsAmount(50.0);

        $points1->subtract($points2);
    }

    public function test_multiply_returns_new_instance_with_product(): void
    {
        $points = new PointsAmount(50.0);

        $result = $points->multiply(2.5);

        $this->assertEquals(125.0, $result->value);
    }

    public function test_multiply_throws_exception_for_negative_multiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier cannot be negative');

        $points = new PointsAmount(50.0);
        $points->multiply(-2.0);
    }

    public function test_percentage_calculates_percentage_of_points(): void
    {
        $points = new PointsAmount(200.0);

        $result = $points->percentage(25.0);

        $this->assertEquals(50.0, $result->value); // 25% of 200
    }

    public function test_percentage_throws_exception_for_invalid_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Percentage must be between 0 and 100');

        $points = new PointsAmount(100.0);
        $points->percentage(150.0);
    }

    public function test_round_rounds_to_nearest_whole_number(): void
    {
        $points = new PointsAmount(50.6);

        $result = $points->round();

        $this->assertEquals(51.0, $result->value);
    }

    public function test_ceil_rounds_up_to_whole_number(): void
    {
        $points = new PointsAmount(50.1);

        $result = $points->ceil();

        $this->assertEquals(51.0, $result->value);
    }

    public function test_floor_rounds_down_to_whole_number(): void
    {
        $points = new PointsAmount(50.9);

        $result = $points->floor();

        $this->assertEquals(50.0, $result->value);
    }

    public function test_min_returns_smaller_of_two_amounts(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(75.0);

        $result = $points1->min($points2);

        $this->assertEquals(75.0, $result->value);
    }

    public function test_max_returns_larger_of_two_amounts(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(75.0);

        $result = $points1->max($points2);

        $this->assertEquals(100.0, $result->value);
    }

    public function test_is_zero_returns_true_for_zero(): void
    {
        $points = PointsAmount::zero();

        $this->assertTrue($points->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero(): void
    {
        $points = new PointsAmount(0.5);

        $this->assertFalse($points->isZero());
    }

    public function test_is_greater_than_compares_correctly(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(50.0);

        $this->assertTrue($points1->isGreaterThan($points2));
        $this->assertFalse($points2->isGreaterThan($points1));
    }

    public function test_is_less_than_compares_correctly(): void
    {
        $points1 = new PointsAmount(50.0);
        $points2 = new PointsAmount(100.0);

        $this->assertTrue($points1->isLessThan($points2));
        $this->assertFalse($points2->isLessThan($points1));
    }

    public function test_equals_returns_true_for_equal_amounts(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(100.0);

        $this->assertTrue($points1->equals($points2));
    }

    public function test_equals_returns_false_for_different_amounts(): void
    {
        $points1 = new PointsAmount(100.0);
        $points2 = new PointsAmount(50.0);

        $this->assertFalse($points1->equals($points2));
    }

    public function test_format_formats_with_specified_decimals(): void
    {
        $points = new PointsAmount(1234.56);

        $formatted = $points->format(2);

        $this->assertEquals('1,234.56', $formatted);
    }

    public function test_to_string_returns_string_representation(): void
    {
        $points = new PointsAmount(100.5);

        $string = $points->toString();

        $this->assertEquals('100.5', $string);
    }

    public function test_to_int_returns_rounded_integer(): void
    {
        $points = new PointsAmount(100.7);

        $int = $points->toInt();

        $this->assertEquals(101, $int);
    }

    public function test_value_object_is_immutable(): void
    {
        $original = new PointsAmount(100.0);
        $result = $original->add(new PointsAmount(50.0));

        // Original should be unchanged
        $this->assertEquals(100.0, $original->value);
        // Result should be a new instance
        $this->assertEquals(150.0, $result->value);
        $this->assertNotSame($original, $result);
    }
}
