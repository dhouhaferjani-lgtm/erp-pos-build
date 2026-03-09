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
}
