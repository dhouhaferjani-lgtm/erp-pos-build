<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\ValueObjects\ScheduleWindow;
use App\Modules\Workshop\Technician\Domain\ValueObjects\WeeklySchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WeeklyScheduleTest extends TestCase
{
    private function standardSchedule(string $tz = 'Europe/Paris'): WeeklySchedule
    {
        return new WeeklySchedule([
            'mon' => [new ScheduleWindow('08:00', '12:00'), new ScheduleWindow('13:00', '17:00')],
            'tue' => [new ScheduleWindow('08:00', '12:00'), new ScheduleWindow('13:00', '17:00')],
            'wed' => [new ScheduleWindow('08:00', '12:00'), new ScheduleWindow('13:00', '17:00')],
            'thu' => [new ScheduleWindow('08:00', '12:00'), new ScheduleWindow('13:00', '17:00')],
            'fri' => [new ScheduleWindow('08:00', '12:00'), new ScheduleWindow('13:00', '17:00')],
            'sat' => [],
            'sun' => [],
        ], $tz);
    }

    public function test_is_within_schedule_for_mon_morning(): void
    {
        // Monday 2026-04-20 09:00 Europe/Paris
        $moment = new \DateTimeImmutable('2026-04-20T09:00:00', new \DateTimeZone('Europe/Paris'));
        $this->assertTrue($this->standardSchedule()->isWithinSchedule($moment));
    }

    public function test_outside_lunch_break_is_not_within_schedule(): void
    {
        $moment = new \DateTimeImmutable('2026-04-20T12:30:00', new \DateTimeZone('Europe/Paris'));
        $this->assertFalse($this->standardSchedule()->isWithinSchedule($moment));
    }

    public function test_sunday_always_outside_schedule(): void
    {
        $moment = new \DateTimeImmutable('2026-04-19T10:00:00', new \DateTimeZone('Europe/Paris'));
        $this->assertFalse($this->standardSchedule()->isWithinSchedule($moment));
    }

    public function test_total_minutes_per_week(): void
    {
        // 5 days * (4h + 4h) = 5 * 480 = 2400 minutes
        $this->assertSame(2400, $this->standardSchedule()->totalMinutesPerWeek());
    }

    public function test_midnight_crossing_window_supported(): void
    {
        $schedule = new WeeklySchedule([
            'mon' => [new ScheduleWindow('22:00', '23:59')],
            'tue' => [new ScheduleWindow('00:00', '02:00')],
            'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
        ], 'Europe/Paris');

        $lateMon = new \DateTimeImmutable('2026-04-20T23:30:00', new \DateTimeZone('Europe/Paris'));
        $earlyTue = new \DateTimeImmutable('2026-04-21T01:00:00', new \DateTimeZone('Europe/Paris'));
        $this->assertTrue($schedule->isWithinSchedule($lateMon));
        $this->assertTrue($schedule->isWithinSchedule($earlyTue));
    }

    public function test_from_json_validates_shape(): void
    {
        $json = [
            'mon' => [['start' => '08:00', 'end' => '12:00']],
            'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
        ];
        $schedule = WeeklySchedule::fromJson($json, 'UTC');
        $this->assertSame(240, $schedule->totalMinutesPerWeek());
        $this->assertCount(1, $schedule->windowsFor('mon'));
    }

    public function test_from_json_rejects_missing_day(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WeeklySchedule::fromJson(['mon' => []], 'UTC');
    }

    public function test_to_json_is_round_trip(): void
    {
        $sched = $this->standardSchedule('Europe/Paris');
        $json = $sched->toJson();
        $rebuilt = WeeklySchedule::fromJson($json, 'Europe/Paris');
        $this->assertSame(2400, $rebuilt->totalMinutesPerWeek());
    }

    public function test_moment_in_other_tz_is_converted_to_company_tz(): void
    {
        // 14:00 UTC === 16:00 Paris (during CEST = UTC+2 in April)
        $utcMoment = new \DateTimeImmutable('2026-04-20T14:00:00', new \DateTimeZone('UTC'));
        $this->assertTrue($this->standardSchedule('Europe/Paris')->isWithinSchedule($utcMoment));

        // 04:00 UTC === 06:00 Paris → before 08:00 schedule
        $early = new \DateTimeImmutable('2026-04-20T04:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($this->standardSchedule('Europe/Paris')->isWithinSchedule($early));
    }
}
