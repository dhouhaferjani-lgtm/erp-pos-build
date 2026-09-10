<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\QuantityScale;
use PHPUnit\Framework\TestCase;

final class QuantityScaleFormatForUnitTest extends TestCase
{
    public function test_unit_precision_metadata_matches_formatted_quantities(): void
    {
        foreach ([null, 0, 2, 6] as $decimals) {
            $formatted = QuantityScale::formatForUnit('1', $decimals);
            $fraction = explode('.', $formatted)[1] ?? '';
            self::assertSame(strlen($fraction), QuantityScale::decimalPlacesForUnit($decimals));
        }
    }

    public function test_zero_decimals_renders_whole_number(): void
    {
        self::assertSame('1', QuantityScale::formatForUnit('1.0000', 0));
        self::assertSame('7', QuantityScale::formatForUnit('7.0000', 0, 'half_up'));
    }

    public function test_three_decimals_pads(): void
    {
        self::assertSame('1.000', QuantityScale::formatForUnit('1.0000', 3));
    }

    public function test_rounding_methods(): void
    {
        self::assertSame('2', QuantityScale::formatForUnit('1.5000', 0, 'half_up'));
        self::assertSame('1', QuantityScale::formatForUnit('1.9000', 0, 'floor'));
        self::assertSame('2', QuantityScale::formatForUnit('1.1000', 0, 'ceil'));
    }

    public function test_null_args_fall_back_to_scale_4_half_up(): void
    {
        self::assertSame('1.0000', QuantityScale::formatForUnit('1.0000', null));
        self::assertSame('2.5001', QuantityScale::formatForUnit('2.50005', null, null));
    }
}
