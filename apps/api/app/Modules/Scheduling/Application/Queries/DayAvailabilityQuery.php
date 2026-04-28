<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Application\Services\CapacityCalculationService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\ValueObjects\AvailabilityWindow;

/**
 * Read-side query returning the day's availability plus booked appointments
 * per bay — feeds the calendar day-view organism.
 *
 * `availability` maps `bay_id => list<AvailabilityWindow>` (free windows);
 * `booked` maps `bay_id => list<{starts_at, ends_at, appointment_id}>`. The
 * frontend can paint both tracks on the same time axis.
 */
final class DayAvailabilityQuery
{
    public function __construct(
        private readonly CapacityCalculationService $capacity,
        private readonly AppointmentRepositoryInterface $appointments,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     availability: array<string, list<AvailabilityWindow>>,
     *     booked: array<string, list<array{appointment_id: string, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, status: string}>>
     * }
     */
    public function run(string $companyId, \DateTimeImmutable $date): array
    {
        $dayStart = $date->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $availability = $this->capacity->availabilityForDay($companyId, $dayStart);

        /** @var array<string, list<array{appointment_id: string, starts_at: \DateTimeImmutable, ends_at: \DateTimeImmutable, status: string}>> $booked */
        $booked = array_fill_keys(array_keys($availability), []);

        $appointments = $this->appointments->findByCompanyInDateRange($companyId, $dayStart, $dayEnd);
        foreach ($appointments as $appt) {
            if ($appt->bay_id === null || ! array_key_exists($appt->bay_id, $booked)) {
                continue;
            }
            $booked[$appt->bay_id][] = [
                'appointment_id' => $appt->id,
                'starts_at' => \DateTimeImmutable::createFromInterface($appt->scheduled_start),
                'ends_at' => \DateTimeImmutable::createFromInterface($appt->scheduled_end),
                'status' => $appt->status->value,
            ];
        }

        return [
            'date' => $dayStart->format('Y-m-d'),
            'availability' => $availability,
            'booked' => $booked,
        ];
    }
}
