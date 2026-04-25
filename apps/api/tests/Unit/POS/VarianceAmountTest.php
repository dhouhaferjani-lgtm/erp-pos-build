<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Shared\Domain\Enums\VarianceDirection;
use PHPUnit\Framework\TestCase;

class VarianceAmountTest extends TestCase
{
    public function test_direction_returns_over_for_positive_amount(): void
    {
        $vo = new VarianceAmount('123.4500', 'EUR');
        $this->assertSame(VarianceDirection::Over, $vo->direction());
    }

    public function test_direction_returns_under_for_negative_amount(): void
    {
        $vo = new VarianceAmount('-0.0050', 'TND');
        $this->assertSame(VarianceDirection::Under, $vo->direction());
    }

    public function test_direction_returns_balanced_for_zero(): void
    {
        $vo = new VarianceAmount('0.0000', 'EUR');
        $this->assertSame(VarianceDirection::Balanced, $vo->direction());
    }

    public function test_abs_strips_minus_sign(): void
    {
        $vo = new VarianceAmount('-5.5000', 'EUR');
        $this->assertSame('5.5000', $vo->abs());
    }

    public function test_abs_positive_amount_unchanged(): void
    {
        $vo = new VarianceAmount('5.5000', 'TND');
        $this->assertSame('5.5000', $vo->abs());
    }

    public function test_abs_zero_amount(): void
    {
        $vo = new VarianceAmount('0.0000', 'EUR');
        $this->assertSame('0.0000', $vo->abs());
    }

    public function test_is_zero_returns_true_for_zero(): void
    {
        $vo = new VarianceAmount('0.0000', 'TND');
        $this->assertTrue($vo->isZero());
    }

    public function test_is_zero_returns_false_for_nonzero_positive(): void
    {
        $vo = new VarianceAmount('123.4500', 'EUR');
        $this->assertFalse($vo->isZero());
    }

    public function test_is_zero_returns_false_for_nonzero_negative(): void
    {
        $vo = new VarianceAmount('-0.0050', 'TND');
        $this->assertFalse($vo->isZero());
    }
}
