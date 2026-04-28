<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Contracts\BayRepositoryInterface;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;

/**
 * Computes, per bay, the list of free `[start, end)` windows on a given day.
 *
 * The operating_hours JSON on each Bay declares weekly opening windows (one
 * or more per weekday). Booked appointments (cancelled/no_show excluded)
 * subtract from those windows. The result feeds the calendar read model
 * and the storefront availability controller (which strips `resource_id`
 * before exposing slots).
 *
 * Semantics are half-open `[starts_at, ends_at)` throughout, matching the
 * database GiST exclusion constraint predicate.
 */
final class CapacityCalculationService
{
    public function __construct(
        private readonly BayRepositoryInterface $bays,
        private readonly AppointmentRepositoryInterface $appointments,
    ) {}

    /**
     * Returns a map of `bay_id => list<AvailabilityWindow>` — only active
     * bays for the given company are included. When a bay has no operating
     * hours for the day's weekday, its entry is an empty list.
     *
     * @return array<string, list<AvailabilityWindow>>
     */
    public function availabilityForDay(string $companyId, \DateTimeImmutable $date): array
    {
        $dayStart = $date->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $weekdayKey = strtolower($dayStart->format('D')); // mon/tue/wed/...

        $bays = $this->bays->listActiveForCompany($companyId);

        // Preload all active-status appointments in the day for efficiency.
        $dayAppointments = $this->appointments
            ->findByCompanyInDateRange($companyId, $dayStart, $dayEnd)
            ->filter(fn ($a): bool => ! in_array($a->status->value, ['cancelled', 'no_show'], true))
            ->values();

        /** @var array<string, list<AvailabilityWindow>> $result */
        $result = [];
        foreach ($bays as $bay) {
            $result[$bay->id] = $this->freeWindowsForBay($bay, $weekdayKey, $dayStart, $dayAppointments);
        }

        return $result;
    }

    /**
     * @param  iterable<Appointment>  $dayAppointments
     * @return list<AvailabilityWindow>
     */
    private function freeWindowsForBay(
        Bay $bay,
        string $weekdayKey,
        \DateTimeImmutable $dayStart,
        iterable $dayAppointments,
    ): array {
        $schedule = $bay->operating_hours[$weekdayKey] ?? [];
        if ($schedule === []) {
            return [];
        }

        // Materialize operating windows for the day.
        /** @var list<AvailabilityWindow> $operating */
        $operating = [];
        foreach ($schedule as $slot) {
            $operating[] = new AvailabilityWindow(
                resource_type: AvailabilityWindow::TYPE_BAY,
                resource_id: $bay->id,
                starts_at: $this->combine($dayStart, $slot['start']),
                ends_at: $this->combine($dayStart, $slot['end']),
            );
        }

        // Collect booked windows for this bay only.
        /** @var list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $booked */
        $booked = [];
        foreach ($dayAppointments as $appt) {
            if ($appt->bay_id !== $bay->id) {
                continue;
            }
            $booked[] = [
                'start' => \DateTimeImmutable::createFromInterface($appt->scheduled_start),
                'end' => \DateTimeImmutable::createFromInterface($appt->scheduled_end),
            ];
        }

        /** @var list<AvailabilityWindow> $free */
        $free = [];
        foreach ($operating as $window) {
            foreach ($this->subtractBookings($window, $booked) as $remnant) {
                $free[] = $remnant;
            }
        }

        return $free;
    }

    /**
     * Subtracts booked `[start, end)` intervals from the given operating window.
     * Booked intervals outside the window are ignored; partial overlaps clip.
     *
     * @param  list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>  $booked
     * @return list<AvailabilityWindow>
     */
    private function subtractBookings(AvailabilityWindow $window, array $booked): array
    {
        // Filter to bookings that actually intersect the window (half-open).
        $intersecting = array_values(array_filter(
            $booked,
            fn (array $b): bool => $b['start'] < $window->ends_at && $b['end'] > $window->starts_at,
        ));

        // Sort by start time.
        usort(
            $intersecting,
            fn (array $a, array $b): int => $a['start'] <=> $b['start'],
        );

        /** @var list<AvailabilityWindow> $remnants */
        $remnants = [];
        $cursor = $window->starts_at;
        foreach ($intersecting as $b) {
            $bStart = $b['start'] < $window->starts_at ? $window->starts_at : $b['start'];
            $bEnd = $b['end'] > $window->ends_at ? $window->ends_at : $b['end'];

            if ($cursor < $bStart) {
                $remnants[] = new AvailabilityWindow(
                    resource_type: $window->resource_type,
                    resource_id: $window->resource_id,
                    starts_at: $cursor,
                    ends_at: $bStart,
                );
            }
            if ($bEnd > $cursor) {
                $cursor = $bEnd;
            }
        }

        if ($cursor < $window->ends_at) {
            $remnants[] = new AvailabilityWindow(
                resource_type: $window->resource_type,
                resource_id: $window->resource_id,
                starts_at: $cursor,
                ends_at: $window->ends_at,
            );
        }

        return $remnants;
    }

    private function combine(\DateTimeImmutable $date, string $hhmm): \DateTimeImmutable
    {
        [$h, $m] = explode(':', $hhmm);

        return $date->setTime((int) $h, (int) $m, 0);
    }
}
