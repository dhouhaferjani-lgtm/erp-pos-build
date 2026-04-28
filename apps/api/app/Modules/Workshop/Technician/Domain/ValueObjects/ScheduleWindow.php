<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Single time window inside a day's schedule (e.g. 08:00–12:00).
 *
 * Contiguous: lower-inclusive, upper-exclusive. Crossing midnight is NOT supported
 * at the window level — callers split across days (mon 22:00→23:59, tue 00:00→02:00).
 */
final readonly class ScheduleWindow
{
    public function __construct(
        public string $start,
        public string $end,
    ) {
        self::assertHhMm($start);
        self::assertHhMm($end);

        if ($this->startMinutes() >= $this->endMinutes()) {
            throw new InvalidArgumentException(
                sprintf('ScheduleWindow end (%s) must be after start (%s).', $end, $start),
            );
        }
    }

    public function startMinutes(): int
    {
        return self::toMinutes($this->start);
    }

    public function endMinutes(): int
    {
        return self::toMinutes($this->end);
    }

    public function durationMinutes(): int
    {
        return $this->endMinutes() - $this->startMinutes();
    }

    public function containsMinute(int $minuteOfDay): bool
    {
        return $minuteOfDay >= $this->startMinutes() && $minuteOfDay < $this->endMinutes();
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return ['start' => $this->start, 'end' => $this->end];
    }

    /**
     * @param  array{start: string, end: string}  $json
     */
    public static function fromArray(array $json): self
    {
        return new self($json['start'], $json['end']);
    }

    private static function assertHhMm(string $value): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'ScheduleWindow time must be HH:MM (00:00–23:59). Got "%s".',
                $value,
            ));
        }
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm, 2);

        return (int) $h * 60 + (int) $m;
    }
}
