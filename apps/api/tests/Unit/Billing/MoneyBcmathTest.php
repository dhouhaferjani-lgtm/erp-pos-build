<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TDD tests for Phase 7.1: Money value object → numeric-string + bcmath.
 *
 * Written BEFORE the bcmath rewrite (red → green). They assert exact decimal
 * behaviour that the previous float implementation could not guarantee
 * (e.g. 0.1 + 0.2 === 0.30 exactly, no IEEE-754 epsilon comparison).
 */
final class MoneyBcmathTest extends TestCase
{
    #[Test]
    public function it_stores_amount_as_normalized_numeric_string_at_currency_scale(): void
    {
        $money = new Money('12.3', 'EUR');

        $this->assertSame('12.30', $money->amount);
        $this->assertIsString($money->amount);
    }

    #[Test]
    public function it_normalizes_to_three_decimals_for_tnd(): void
    {
        $money = new Money('12.3', 'TND');

        $this->assertSame('12.300', $money->amount);
    }

    #[Test]
    public function it_normalizes_to_zero_decimals_for_jpy(): void
    {
        $money = new Money('1200.4', 'JPY');

        $this->assertSame('1200', $money->amount);
    }

    #[Test]
    public function it_truncates_excess_precision_at_currency_scale(): void
    {
        $money = new Money('12.349', 'EUR');

        // bcadd truncates (does not round) at scale.
        $this->assertSame('12.34', $money->amount);
    }

    #[Test]
    public function it_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money('-1.00', 'EUR');
    }

    #[Test]
    public function it_rejects_non_numeric_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money('abc', 'EUR');
    }

    #[Test]
    public function add_is_exact_without_float_drift(): void
    {
        $result = (new Money('0.1', 'EUR'))->add(new Money('0.2', 'EUR'));

        $this->assertSame('0.30', $result->amount);
        $this->assertTrue($result->equals(new Money('0.30', 'EUR')));
    }

    #[Test]
    public function subtract_is_exact(): void
    {
        $result = (new Money('0.30', 'EUR'))->subtract(new Money('0.10', 'EUR'));

        $this->assertSame('0.20', $result->amount);
    }

    #[Test]
    public function subtract_rejects_negative_result(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money('1.00', 'EUR'))->subtract(new Money('2.00', 'EUR'));
    }

    #[Test]
    public function multiply_is_exact_when_product_lands_on_scale(): void
    {
        // 19.99 * 3 = 59.97 exactly
        $result = (new Money('19.99', 'EUR'))->multiply('3');

        $this->assertSame('59.97', $result->amount);
    }

    #[Test]
    public function multiply_truncates_sub_cent_product_toward_zero(): void
    {
        // 10.00 * 0.0375 = 0.375 → normalised to EUR scale 2 TRUNCATES to 0.37
        // (NOT half-up 0.38) — this pins the documented truncation semantics so
        // a future switch to half-up rounding is a deliberate, test-visible change.
        $result = (new Money('10.00', 'EUR'))->multiply('0.0375');

        $this->assertSame('0.37', $result->amount);
    }

    #[Test]
    public function equals_uses_bccomp_without_tolerance(): void
    {
        $this->assertTrue((new Money('1.00', 'EUR'))->equals(new Money('1.00', 'EUR')));
        $this->assertTrue((new Money('1.001', 'EUR'))->equals(new Money('1.00', 'EUR')));
        $this->assertFalse((new Money('1.01', 'EUR'))->equals(new Money('1.00', 'EUR')));
    }

    #[Test]
    public function equals_is_false_for_different_currency(): void
    {
        $this->assertFalse((new Money('1.00', 'EUR'))->equals(new Money('1.00', 'USD')));
    }

    #[Test]
    public function to_cents_returns_integer_minor_units(): void
    {
        $this->assertSame(1234, (new Money('12.34', 'EUR'))->toCents());
    }

    #[Test]
    public function to_cents_respects_currency_scale_for_three_decimal_currency(): void
    {
        // TND uses 3 decimal places → 1000 minor units per dinar.
        $this->assertSame(12340, (new Money('12.340', 'TND'))->toCents());
    }

    #[Test]
    public function to_cents_respects_currency_scale_for_zero_decimal_currency(): void
    {
        $this->assertSame(1200, (new Money('1200', 'JPY'))->toCents());
    }

    #[Test]
    public function from_cents_round_trips(): void
    {
        $money = Money::fromCents(1234, 'EUR');

        $this->assertSame('12.34', $money->amount);
        $this->assertSame(1234, $money->toCents());
    }

    #[Test]
    public function from_cents_round_trips_for_tnd(): void
    {
        $money = Money::fromCents(12340, 'TND');

        $this->assertSame('12.340', $money->amount);
    }

    #[Test]
    public function arithmetic_rejects_cross_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money('1.00', 'EUR'))->add(new Money('1.00', 'USD'));
    }

    #[Test]
    public function less_than_and_greater_than_use_bccomp(): void
    {
        $a = new Money('1.00', 'EUR');
        $b = new Money('2.00', 'EUR');

        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($b->greaterThan($a));
        $this->assertFalse($a->greaterThan($b));
    }

    #[Test]
    public function is_zero_is_exact(): void
    {
        $this->assertTrue((new Money('0', 'EUR')->isZero()));
        $this->assertTrue((new Money('0.00', 'EUR'))->isZero());
        $this->assertFalse((new Money('0.01', 'EUR'))->isZero());
    }

    #[Test]
    public function format_renders_at_currency_scale(): void
    {
        $this->assertSame("\u{20AC}12.30", (new Money('12.30', 'EUR'))->format());
        $this->assertSame('TND12.300', (new Money('12.300', 'TND'))->format());
        $this->assertSame("\u{00A5}1200", (new Money('1200', 'JPY'))->format());
    }
}
