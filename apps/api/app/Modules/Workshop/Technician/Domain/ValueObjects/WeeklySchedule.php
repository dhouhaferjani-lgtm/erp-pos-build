<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Typed wrapper over the weekly_schedule JSONB blob.
 *
 * The schedule windows are interpreted in the company's configured timezone
 * (constructor `$timezone` param). `isWithinSchedule($moment)` converts the
 * caller-supplied `DateTimeImmutable` to that timezone before comparing.
 */
final readonly class WeeklySchedule
{
    /** @var list<string> */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<string, list<ScheduleWindow>> */
    public array $windowsByDay;

    public string $timezone;

    /**
     * @param  array<string, list<ScheduleWindow>>  $windowsByDay  Keys: mon, tue, wed, thu, fri, sat, sun.
     */
    public function __construct(array $windowsByDay, string $timezone)
    {
        foreach (self::DAYS as $day) {
            if (! array_key_exists($day, $windowsByDay)) {
                throw new InvalidArgumentException(sprintf(
                    'WeeklySchedule requires all 7 days. Missing: %s',
                    $day,
                ));
            }
        }

        // Normalize key order and drop unknown keys.
        $normalized = [];
        foreach (self::DAYS as $day) {
            $normalized[$day] = $windowsByDay[$day];
        }

        // Validate timezone is known to PHP.
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid WeeklySchedule timezone "%s": %s', $timezone, $e->getMessage()),
            );
        }

        $this->windowsByDay = $normalized;
        $this->timezone = $timezone;
    }

    /**
     * @return list<ScheduleWindow>
     */
    public function windowsFor(string $day): array
    {
        if (! array_key_exists($day, $this->windowsByDay)) {
            throw new InvalidArgumentException(sprintf('Unknown day key "%s".', $day));
        }

        return $this->windowsByDay[$day];
    }

    public function totalMinutesPerWeek(): int
    {
        $total = 0;
        foreach ($this->windowsByDay as $windows) {
            foreach ($windows as $window) {
                $total += $window->durationMinutes();
            }
        }

        return $total;
    }

    public function isWithinSchedule(\DateTimeImmutable $moment): bool
    {
        $local = $moment->setTimezone(new \DateTimeZone($this->timezone));
        $dayKey = strtolower($local->format('D')); // Mon|Tue|...|Sun → mon|tue|...
        $dayKey = substr($dayKey, 0, 3);

        $windows = $this->windowsByDay[$dayKey] ?? [];
        if ($windows === []) {
            return false;
        }

        $minuteOfDay = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        foreach ($windows as $window) {
            if ($window->containsMinute($minuteOfDay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<array{start: string, end: string}>>  $raw
     */
    public static function fromJson(array $raw, string $timezone): self
    {
        $windowsByDay = [];
        foreach (self::DAYS as $day) {
            if (! array_key_exists($day, $raw)) {
                throw new InvalidArgumentException(sprintf(
                    'WeeklySchedule::fromJson requires all 7 day keys. Missing: %s',
                    $day,
                ));
            }
            $windowsByDay[$day] = array_map(
                static fn (array $w): ScheduleWindow => ScheduleWindow::fromArray($w),
                $raw[$day],
            );
        }

        return new self($windowsByDay, $timezone);
    }

    /**
     * @return array<string, list<array{start: string, end: string}>>
     */
    public function toJson(): array
    {
        $out = [];
        foreach (self::DAYS as $day) {
            $out[$day] = array_map(
                static fn (ScheduleWindow $w): array => $w->toArray(),
                $this->windowsByDay[$day],
            );
        }

        return $out;
    }
}
