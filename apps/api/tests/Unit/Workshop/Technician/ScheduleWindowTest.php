<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\ValueObjects\ScheduleWindow;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleWindowTest extends TestCase
{
    public function test_minutes_calculation(): void
    {
        $w = new ScheduleWindow('08:00', '12:30');
        $this->assertSame(480, $w->startMinutes());
        $this->assertSame(750, $w->endMinutes());
        $this->assertSame(270, $w->durationMinutes());
    }

    public function test_contains_minute_inclusive_lower_exclusive_upper(): void
    {
        $w = new ScheduleWindow('08:00', '12:00');
        $this->assertTrue($w->containsMinute(480));
        $this->assertTrue($w->containsMinute(719));
        $this->assertFalse($w->containsMinute(720));
        $this->assertFalse($w->containsMinute(479));
    }

    public function test_invalid_format_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduleWindow('8am', '12:00');
    }

    public function test_end_before_start_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduleWindow('14:00', '10:00');
    }
}
