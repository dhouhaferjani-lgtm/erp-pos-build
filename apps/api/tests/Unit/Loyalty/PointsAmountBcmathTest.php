<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty;

use App\Modules\Loyalty\Domain\ValueObjects\PointsAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bcmath / numeric-string contract for PointsAmount.
 *
 * Points are stored at the canonical loyalty scale of 3 (matching
 * loyalty_transactions.amount NUMERIC(15,3) and the services' FALLBACK_SCALE=3).
 * No float arithmetic, no tolerance/epsilon comparisons.
 */
final class PointsAmountBcmathTest extends TestCase
{
    public function test_constructor_takes_numeric_string_and_stores_canonical_string(): void
    {
        $points = new PointsAmount('100.5');

        $this->assertSame('100.500', $points->value);
        $this->assertIsString($points->value);
    }

    public function test_rejects_non_numeric_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PointsAmount('abc');
    }

    public function test_rejects_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount cannot be negative');

        new PointsAmount('-10');
    }

    public function test_rejects_value_exceeding_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Points amount exceeds maximum allowed value');

        new PointsAmount('1000000000');
    }

    public function test_add_is_exact_with_no_float_drift(): void
    {
        // The canonical float-drift example: 0.1 + 0.2 must equal exactly 0.3.
        $result = (new PointsAmount('0.1'))->add(new PointsAmount('0.2'));

        $this->assertSame('0.300', $result->value);
        $this->assertTrue($result->equals(new PointsAmount('0.3')));
    }

    public function test_accumulator_does_not_drift_over_many_additions(): void
    {
        // 10,000 × 0.1 must be exactly 1000.000 (the audit's worked example).
        $total = PointsAmount::zero();
        for ($i = 0; $i < 10000; $i++) {
            $total = $total->add(new PointsAmount('0.1'));
        }

        $this->assertSame('1000.000', $total->value);
        $this->assertTrue($total->equals(new PointsAmount('1000')));
    }

    public function test_subtract_is_exact(): void
    {
        $result = (new PointsAmount('100'))->subtract(new PointsAmount('0.001'));

        $this->assertSame('99.999', $result->value);
    }

    public function test_subtract_rejects_negative_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot subtract more points than available');

        (new PointsAmount('30'))->subtract(new PointsAmount('50'));
    }

    public function test_multiply_uses_bcmath(): void
    {
        $result = (new PointsAmount('50'))->multiply('2.5');

        $this->assertSame('125.000', $result->value);
    }

    public function test_percentage_uses_bcmath(): void
    {
        $result = (new PointsAmount('200'))->percentage('25');

        $this->assertSame('50.000', $result->value);
    }

    public function test_min_and_max_use_bccomp(): void
    {
        $a = new PointsAmount('100');
        $b = new PointsAmount('75');

        $this->assertSame('75.000', $a->min($b)->value);
        $this->assertSame('100.000', $a->max($b)->value);
    }

    public function test_is_zero_uses_bccomp_no_tolerance(): void
    {
        $this->assertTrue(PointsAmount::zero()->isZero());
        $this->assertFalse((new PointsAmount('0.001'))->isZero());
    }

    public function test_comparisons_use_bccomp(): void
    {
        $a = new PointsAmount('100');
        $b = new PointsAmount('50');

        $this->assertTrue($a->isGreaterThan($b));
        $this->assertTrue($b->isLessThan($a));
        $this->assertFalse($a->isLessThan($b));
    }

    public function test_equals_uses_bccomp_at_canonical_scale(): void
    {
        // 100.000 vs 100.0004 — beyond scale 3 they are equal after canonicalisation.
        $this->assertTrue((new PointsAmount('100'))->equals(new PointsAmount('100.0004')));
        $this->assertFalse((new PointsAmount('100'))->equals(new PointsAmount('100.001')));
    }

    public function test_from_numeric_canonicalises_float(): void
    {
        $points = PointsAmount::fromNumeric(75.25);

        $this->assertSame('75.250', $points->value);
    }

    public function test_from_numeric_string_canonicalises_string(): void
    {
        $points = PointsAmount::fromNumericString('42.5');

        $this->assertSame('42.500', $points->value);
    }

    public function test_to_string_returns_canonical_string(): void
    {
        $this->assertSame('100.500', (new PointsAmount('100.5'))->toString());
    }

    public function test_to_int_rounds_half_up(): void
    {
        $this->assertSame(101, (new PointsAmount('100.7'))->toInt());
        $this->assertSame(100, (new PointsAmount('100.4'))->toInt());
    }

    public function test_round_ceil_floor(): void
    {
        $this->assertSame('51.000', (new PointsAmount('50.6'))->round()->value);
        $this->assertSame('51.000', (new PointsAmount('50.1'))->ceil()->value);
        $this->assertSame('50.000', (new PointsAmount('50.9'))->floor()->value);
    }
}
