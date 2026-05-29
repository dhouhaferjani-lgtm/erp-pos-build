<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TDD tests for Task 0.1: bcformatStrict + bcformatOrNull additions to CurrencyScale.
 *
 * These tests are written BEFORE the implementation exists (red → green cycle).
 */
final class CurrencyScaleBcformatStrictTest extends TestCase
{
    // -----------------------------------------------------------------------
    // bcformatStrict — rejects non-numeric input
    // -----------------------------------------------------------------------

    #[Test]
    public function test_bcformat_strict_rejects_non_numeric_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatStrict('abc', 2);
    }

    #[Test]
    public function test_bcformat_strict_rejects_whitespace_only_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatStrict('   ', 2);
    }

    #[Test]
    public function test_bcformat_strict_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatStrict('', 2);
    }

    #[Test]
    public function test_bcformat_strict_accepts_canonical_decimal_string(): void
    {
        $result = CurrencyScale::bcformatStrict('5.000', 3);

        $this->assertSame('5.000', $result);
    }

    #[Test]
    public function test_bcformat_strict_accepts_integer_string(): void
    {
        $result = CurrencyScale::bcformatStrict('42', 2);

        $this->assertSame('42.00', $result);
    }

    #[Test]
    public function test_bcformat_strict_accepts_negative_decimal_string(): void
    {
        $result = CurrencyScale::bcformatStrict('-10.500', 3);

        $this->assertSame('-10.500', $result);
    }

    #[Test]
    public function test_bcformat_strict_accepts_zero_string(): void
    {
        $result = CurrencyScale::bcformatStrict('0', 3);

        $this->assertSame('0.000', $result);
    }

    #[Test]
    public function test_bcformat_strict_rejects_mixed_alphanumeric_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatStrict('12abc', 2);
    }

    // -----------------------------------------------------------------------
    // bcformatOrNull — preserves null; delegates to strict for non-null
    // -----------------------------------------------------------------------

    #[Test]
    public function test_bcformat_or_null_preserves_null(): void
    {
        $result = CurrencyScale::bcformatOrNull(null, 3);

        $this->assertNull($result);
    }

    #[Test]
    public function test_bcformat_or_null_formats_non_null_value(): void
    {
        $result = CurrencyScale::bcformatOrNull('5.000', 3);

        $this->assertSame('5.000', $result);
    }

    #[Test]
    public function test_bcformat_or_null_formats_non_null_integer_string(): void
    {
        $result = CurrencyScale::bcformatOrNull('100', 2);

        $this->assertSame('100.00', $result);
    }

    #[Test]
    public function test_bcformat_or_null_rejects_non_numeric_non_null_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatOrNull('bad-value', 2);
    }

    #[Test]
    public function test_bcformat_or_null_rejects_whitespace_only_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurrencyScale::bcformatOrNull('   ', 2);
    }

    // -----------------------------------------------------------------------
    // bcformat legacy — deprecation notice emitted when null is passed
    // -----------------------------------------------------------------------

    #[Test]
    public function test_bcformat_legacy_emits_deprecation_when_null_passed(): void
    {
        // Capture the E_USER_DEPRECATED notice emitted by the legacy bcformat on null input.
        $deprecationTriggered = false;
        $previousHandler = set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }

            return true; // suppress the error from bubbling
        });

        CurrencyScale::bcformat(null, 2);

        restore_error_handler();

        $this->assertTrue($deprecationTriggered, 'bcformat(null, ...) must emit E_USER_DEPRECATED');
    }
}
