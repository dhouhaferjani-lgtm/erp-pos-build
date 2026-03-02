<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\POS\Domain\Enums\ShiftStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShiftStatus enum
 */
class ShiftStatusEnumTest extends TestCase
{
    public function test_open_has_correct_value(): void
    {
        $this->assertEquals('OPEN', ShiftStatus::Open->value);
    }

    public function test_closed_has_correct_value(): void
    {
        $this->assertEquals('CLOSED', ShiftStatus::Closed->value);
    }

    public function test_can_be_created_from_string(): void
    {
        $this->assertEquals(ShiftStatus::Open, ShiftStatus::from('OPEN'));
        $this->assertEquals(ShiftStatus::Closed, ShiftStatus::from('CLOSED'));
    }

    public function test_invalid_value_throws_error(): void
    {
        $this->expectException(\ValueError::class);
        ShiftStatus::from('INVALID');
    }

    public function test_try_from_returns_null_for_invalid(): void
    {
        $this->assertNull(ShiftStatus::tryFrom('INVALID'));
    }
}
