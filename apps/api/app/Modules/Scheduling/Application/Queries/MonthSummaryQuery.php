<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;

/**
 * Lightweight month-view summary: per-date count of scheduled appointments
 * and, for each, the aggregated booked minutes for a company.
 *
 * Used by the calendar month grid; the day/week views call
 * {@see DayAvailabilityQuery} for the detailed resource-per-track layout.
 */
final class MonthSummaryQuery
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
    ) {}

    /**
     * @return array<string, array{date: string, appointment_count: int, booked_minutes: int}>
     */
    public function run(string $companyId, int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $to = $from->modify('+1 month');

        $rows = $this->appointments->findByCompanyInDateRange($companyId, $from, $to);

        /** @var array<string, array{date: string, appointment_count: int, booked_minutes: int}> $summary */
        $summary = [];
        foreach ($rows as $appt) {
            if (in_array($appt->status->value, ['cancelled', 'no_show'], true)) {
                continue;
            }
            $dateKey = $appt->scheduled_start->format('Y-m-d');
            $duration = (int) (($appt->scheduled_end->getTimestamp() - $appt->scheduled_start->getTimestamp()) / 60);
            if (! isset($summary[$dateKey])) {
                $summary[$dateKey] = [
                    'date' => $dateKey,
                    'appointment_count' => 0,
                    'booked_minutes' => 0,
                ];
            }
            $summary[$dateKey]['appointment_count']++;
            $summary[$dateKey]['booked_minutes'] += $duration;
        }

        ksort($summary);

        return $summary;
    }
}
