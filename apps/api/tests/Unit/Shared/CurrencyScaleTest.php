<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\CurrencyScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyScaleTest extends TestCase
{
    #[Test]
    #[DataProvider('iso4217Provider')]
    public function it_returns_correct_decimal_places_for_currency(string $code, int $expected): void
    {
        $this->assertSame($expected, CurrencyScale::for($code));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function iso4217Provider(): array
    {
        return [
            'TND (3 decimals)' => ['TND', 3],
            'LYD (3 decimals)' => ['LYD', 3],
            'BHD (3 decimals)' => ['BHD', 3],
            'IQD (3 decimals)' => ['IQD', 3],
            'JOD (3 decimals)' => ['JOD', 3],
            'KWD (3 decimals)' => ['KWD', 3],
            'OMR (3 decimals)' => ['OMR', 3],
            'EUR (2 decimals)' => ['EUR', 2],
            'USD (2 decimals)' => ['USD', 2],
            'GBP (2 decimals)' => ['GBP', 2],
            'JPY (0 decimals)' => ['JPY', 0],
            'KRW (0 decimals)' => ['KRW', 0],
            'Unknown defaults to 2' => ['XYZ', 2],
        ];
    }

    #[Test]
    public function it_is_case_insensitive(): void
    {
        $this->assertSame(3, CurrencyScale::for('tnd'));
        $this->assertSame(0, CurrencyScale::for('jpy'));
    }

    #[Test]
    public function value_object_equality(): void
    {
        $a = new CurrencyScale('TND');
        $b = new CurrencyScale('TND');
        $c = new CurrencyScale('EUR');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    #[Test]
    public function value_object_to_string(): void
    {
        $scale = new CurrencyScale('TND');
        $this->assertSame('3', (string) $scale);
    }

    #[Test]
    public function value_returns_scale_from_instance(): void
    {
        $this->assertSame(3, (new CurrencyScale('TND'))->value());
        $this->assertSame(2, (new CurrencyScale('EUR'))->value());
        $this->assertSame(0, (new CurrencyScale('JPY'))->value());
    }

    // -- bcformat tests --

    #[Test]
    #[DataProvider('bcformatBasicProvider')]
    public function bcformat_formats_value_to_correct_scale(string|int|float|null $input, int $scale, string $expected): void
    {
        $this->assertSame($expected, CurrencyScale::bcformat($input, $scale));
    }

    /**
     * @return array<string, array{string|int|float|null, int, string}>
     */
    public static function bcformatBasicProvider(): array
    {
        return [
            // String inputs (most common — from DB)
            'string 5 to 3 decimals' => ['5', 3, '5.000'],
            'string 5.0 to 3 decimals' => ['5.0', 3, '5.000'],
            'string 5.000 to 3 decimals' => ['5.000', 3, '5.000'],
            'string 5.0000 to 4 decimals' => ['5.0000', 4, '5.0000'],
            'string 5.0000 to 3 decimals (truncate)' => ['5.0000', 3, '5.000'],
            'string 10.500 to 2 decimals' => ['10.500', 2, '10.50'],
            'string 0 to 3 decimals' => ['0', 3, '0.000'],
            'string 0.001 to 3 decimals' => ['0.001', 3, '0.001'],
            'string large number' => ['999999999.999', 3, '999999999.999'],

            // Integer inputs
            'int 5 to 3 decimals' => [5, 3, '5.000'],
            'int 0 to 2 decimals' => [0, 2, '0.00'],
            'int 100 to 0 decimals (JPY)' => [100, 0, '100'],

            // Null input
            'null to 3 decimals' => [null, 3, '0.000'],
            'null to 2 decimals' => [null, 2, '0.00'],

            // Negative values
            'negative string' => ['-5.500', 3, '-5.500'],
            'negative int' => [-10, 2, '-10.00'],

            // Scale 0 (JPY-like)
            'string to 0 decimals' => ['1234', 0, '1234'],
            'string with decimals to 0' => ['1234.567', 0, '1234'],
        ];
    }

    #[Test]
    public function bcformat_avoids_ieee754_precision_loss(): void
    {
        // THE bug that caused 5.000 TND → 4.999
        // number_format((float) "5.0000", 4, '.', '') can return "4.9990" in edge cases
        // bcformat must NEVER produce this
        $this->assertSame('5.000', CurrencyScale::bcformat('5.0000', 3));
        $this->assertSame('5.0000', CurrencyScale::bcformat('5.0000', 4));
        $this->assertSame('5.000', CurrencyScale::bcformat('5.000', 3));
        $this->assertSame('5.00', CurrencyScale::bcformat('5.000', 2));
    }

    #[Test]
    public function bcformat_preserves_precision_for_common_tnd_prices(): void
    {
        // Common TND price points that must round-trip cleanly
        $tndPrices = [
            '0.500', '1.000', '1.500', '2.000', '2.500',
            '3.000', '3.500', '4.000', '4.500', '5.000',
            '7.500', '10.000', '12.500', '15.000', '20.000',
            '25.000', '50.000', '99.990', '100.000',
        ];

        foreach ($tndPrices as $price) {
            $result = CurrencyScale::bcformat($price, 3);
            $this->assertSame($price, $result, "TND price {$price} must round-trip cleanly");
        }
    }

    #[Test]
    public function bcformat_handles_float_with_scientific_notation(): void
    {
        // PHP float 0.00001 becomes "1.0E-5" via (string) cast,
        // which bcmath cannot parse. bcformat must handle this.
        $result = CurrencyScale::bcformat(0.00001, 6);
        $this->assertSame('0.000010', $result);
    }

    #[Test]
    public function bcformat_handles_empty_string(): void
    {
        $this->assertSame('0.000', CurrencyScale::bcformat('', 3));
        $this->assertSame('0.00', CurrencyScale::bcformat('  ', 2));
    }

    #[Test]
    public function bcformat_result_is_valid_numeric_string(): void
    {
        // Every result should be parseable by bcmath
        $values = ['5.0000', '0', '-3.14', '999999.999', null, '', 42];

        foreach ($values as $value) {
            $result = CurrencyScale::bcformat($value, 3);
            // bcadd will throw ValueError if not numeric
            $sum = bcadd($result, '0', 3);
            $this->assertSame($result, $sum, "bcformat result for " . var_export($value, true) . " must be a valid numeric-string");
        }
    }

    #[Test]
    public function bcformat_does_not_use_float_internally_for_string_input(): void
    {
        // Values known to cause IEEE 754 precision issues when cast to float:
        // (float) "0.1" + (float) "0.2" != 0.3
        // Verify bcformat handles these without float artifacts
        $this->assertSame('0.100', CurrencyScale::bcformat('0.1', 3));
        $this->assertSame('0.200', CurrencyScale::bcformat('0.2', 3));
        $this->assertSame('0.300', CurrencyScale::bcformat('0.3', 3));

        // Verify bcmath arithmetic on bcformat results is precise
        $a = CurrencyScale::bcformat('0.1', 3);
        $b = CurrencyScale::bcformat('0.2', 3);
        $sum = bcadd($a, $b, 3);
        $this->assertSame('0.300', $sum, "0.1 + 0.2 must equal 0.300 (not 0.30000000000000004)");
    }

    #[Test]
    #[DataProvider('bcformatCompoundArithmeticProvider')]
    public function bcformat_enables_precise_compound_arithmetic(string $a, string $b, string $op, int $scale, string $expected): void
    {
        $fa = CurrencyScale::bcformat($a, $scale);
        $fb = CurrencyScale::bcformat($b, $scale);

        $result = match ($op) {
            'add' => bcadd($fa, $fb, $scale),
            'sub' => bcsub($fa, $fb, $scale),
            'mul' => bcmul($fa, $fb, $scale),
            'div' => bcdiv($fa, $fb, $scale),
        };

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, string, string, int, string}>
     */
    public static function bcformatCompoundArithmeticProvider(): array
    {
        return [
            // TND (3 decimals) arithmetic
            'TND: 5.000 + 3.500' => ['5.000', '3.500', 'add', 3, '8.500'],
            'TND: 10.000 - 5.500' => ['10.000', '5.500', 'sub', 3, '4.500'],
            'TND: 5.000 * 3' => ['5.000', '3', 'mul', 3, '15.000'],
            'TND: 10.000 / 3' => ['10.000', '3', 'div', 3, '3.333'],

            // EUR (2 decimals) arithmetic
            'EUR: 19.99 + 5.01' => ['19.99', '5.01', 'add', 2, '25.00'],
            'EUR: 100.00 / 3' => ['100.00', '3', 'div', 2, '33.33'],

            // Price * quantity — the common POS operation
            'TND: unit_price * qty (9.990 * 2)' => ['9.990', '2', 'mul', 3, '19.980'],
            'TND: unit_price * qty (4.990 * 3)' => ['4.990', '3', 'mul', 3, '14.970'],
        ];
    }
}
