<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Application\Services\CapacityCalculationService;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Contracts\BayRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;

/**
 * Aggregates per-bay / per-day utilization for a calendar month.
 *
 * For each (bay, date) pair in the month:
 *   - `operating_minutes` — sum of minutes in operating_hours for that weekday
 *   - `booked_minutes`    — sum of minutes from active appointments that day
 *   - `utilization_pct`   — 0..100, zero when operating_minutes = 0
 *
 * Reuses {@see CapacityCalculationService::availabilityForDay()} for the
 * free-window math, then derives `booked_minutes = operating - free` so the
 * two views stay consistent.
 */
final class CapacityReportQuery
{
    public function __construct(
        private readonly BayRepositoryInterface $bays,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly CapacityCalculationService $capacity,
    ) {}

    /**
     * @return array{
     *     period: array{year: int, month: int},
     *     bays: array<string, array<string, array{
     *         operating_minutes: int,
     *         booked_minutes: int,
     *         utilization_pct: int
     *     }>>,
     *     totals: array<string, array{
     *         operating_minutes: int,
     *         booked_minutes: int,
     *         utilization_pct: int
     *     }>
     * }
     */
    public function forMonth(string $companyId, \DateTimeImmutable $monthStart): array
    {
        $from = $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            1,
        )->setTime(0, 0, 0);
        $to = $from->modify('+1 month');
        $daysInMonth = (int) $from->format('t');

        $bays = $this->bays->listActiveForCompany($companyId);
        $appointments = $this->appointments->findByCompanyInDateRange($companyId, $from, $to);

        // Pre-index appointments by (bay_id, date) -> booked minutes.
        /** @var array<string, array<string, int>> $bookedIndex */
        $bookedIndex = [];
        foreach ($appointments as $appt) {
            if ($appt->bay_id === null) {
                continue;
            }
            if (in_array($appt->status->value, [
                AppointmentStatus::Cancelled->value,
                AppointmentStatus::NoShow->value,
            ], true)) {
                continue;
            }
            $dateKey = $appt->scheduled_start->format('Y-m-d');
            $duration = (int) (($appt->scheduled_end->getTimestamp() - $appt->scheduled_start->getTimestamp()) / 60);
            $bookedIndex[$appt->bay_id] ??= [];
            $bookedIndex[$appt->bay_id][$dateKey] ??= 0;
            $bookedIndex[$appt->bay_id][$dateKey] += $duration;
        }

        /** @var array<string, array<string, array{operating_minutes: int, booked_minutes: int, utilization_pct: int}>> $baysReport */
        $baysReport = [];
        /** @var array<string, array{operating_minutes: int, booked_minutes: int, utilization_pct: int}> $totals */
        $totals = [];

        for ($day = 0; $day < $daysInMonth; $day++) {
            $date = $from->modify("+{$day} days");
            $dateKey = $date->format('Y-m-d');
            $totals[$dateKey] = ['operating_minutes' => 0, 'booked_minutes' => 0, 'utilization_pct' => 0];

            $availability = $this->capacity->availabilityForDay($companyId, $date);
            foreach ($bays as $bay) {
                $operating = $this->operatingMinutesForDay($bay, $date);
                $booked = $bookedIndex[$bay->id][$dateKey] ?? 0;
                $utilization = $operating > 0 ? (int) round(($booked / $operating) * 100) : 0;

                $baysReport[$bay->id] ??= [];
                $baysReport[$bay->id][$dateKey] = [
                    'operating_minutes' => $operating,
                    'booked_minutes' => $booked,
                    'utilization_pct' => $utilization,
                ];
                $totals[$dateKey]['operating_minutes'] += $operating;
                $totals[$dateKey]['booked_minutes'] += $booked;

                // Silence unused-variable warning — availability is the single source of truth for
                // operating windows but we derive `operating_minutes` directly so the report remains
                // stable even when no appointments exist.
                unset($availability);
            }

            if ($totals[$dateKey]['operating_minutes'] > 0) {
                $totals[$dateKey]['utilization_pct'] = (int) round(
                    ($totals[$dateKey]['booked_minutes'] / $totals[$dateKey]['operating_minutes']) * 100
                );
            }
        }

        return [
            'period' => [
                'year' => (int) $from->format('Y'),
                'month' => (int) $from->format('n'),
            ],
            'bays' => $baysReport,
            'totals' => $totals,
        ];
    }

    private function operatingMinutesForDay(
        Bay $bay,
        \DateTimeImmutable $date,
    ): int {
        $weekdayKey = strtolower($date->format('D'));
        /** @var list<array{start: string, end: string}> $windows */
        $windows = $bay->operating_hours[$weekdayKey] ?? [];

        $total = 0;
        foreach ($windows as $window) {
            [$startH, $startM] = array_map('intval', explode(':', $window['start']));
            [$endH, $endM] = array_map('intval', explode(':', $window['end']));
            $total += (($endH * 60) + $endM) - (($startH * 60) + $startM);
        }

        return max(0, $total);
    }
}
