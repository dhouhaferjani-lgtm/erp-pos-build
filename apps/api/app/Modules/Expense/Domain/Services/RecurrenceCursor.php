<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain\Services;

use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

final class RecurrenceCursor
{
    public static function next(
        CarbonImmutable $startDate,
        RecurrenceFrequency $frequency,
        CarbonImmutable $current,
    ): CarbonImmutable {
        if ($current->isBefore($startDate)) {
            return $startDate;
        }

        $step = self::monthsPerOccurrence($frequency);
        $occurrence = max(1, intdiv(self::monthsBetween($startDate, $current), $step));
        $candidate = self::occurrence($startDate, $step, $occurrence);

        while ($candidate->lessThanOrEqualTo($current)) {
            $occurrence++;
            $candidate = self::occurrence($startDate, $step, $occurrence);
        }

        return $candidate;
    }

    public static function firstOnOrAfter(
        CarbonImmutable $startDate,
        RecurrenceFrequency $frequency,
        CarbonImmutable $today,
    ): CarbonImmutable {
        if ($today->lessThanOrEqualTo($startDate)) {
            return $startDate;
        }

        $step = self::monthsPerOccurrence($frequency);
        $occurrence = intdiv(self::monthsBetween($startDate, $today), $step);
        $candidate = self::occurrence($startDate, $step, $occurrence);

        while ($candidate->isBefore($today)) {
            $occurrence++;
            $candidate = self::occurrence($startDate, $step, $occurrence);
        }

        return $candidate;
    }

    public static function periodKey(CarbonImmutable $due, RecurrenceFrequency $frequency): string
    {
        return match ($frequency) {
            RecurrenceFrequency::Monthly => $due->format('Y-m'),
            RecurrenceFrequency::Quarterly => sprintf(
                '%s-Q%d',
                $due->format('Y'),
                intdiv($due->month - 1, 3) + 1,
            ),
            RecurrenceFrequency::Yearly => $due->format('Y'),
        };
    }

    private static function monthsPerOccurrence(RecurrenceFrequency $frequency): int
    {
        return match ($frequency) {
            RecurrenceFrequency::Monthly => 1,
            RecurrenceFrequency::Quarterly => 3,
            RecurrenceFrequency::Yearly => 12,
        };
    }

    private static function monthsBetween(CarbonImmutable $startDate, CarbonImmutable $date): int
    {
        return (($date->year - $startDate->year) * 12) + $date->month - $startDate->month;
    }

    private static function occurrence(CarbonImmutable $startDate, int $step, int $occurrence): CarbonImmutable
    {
        return $startDate->addMonthsNoOverflow($step * $occurrence);
    }
}
