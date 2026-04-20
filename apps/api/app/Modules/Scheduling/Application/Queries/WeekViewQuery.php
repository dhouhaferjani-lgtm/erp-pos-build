<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;

/**
 * Read-side query returning a 7-day roll-up of appointments for a company.
 *
 * Result shape — keyed by ISO date (YYYY-MM-DD), in chronological order —
 * enables the week-view calendar organism to paint per-day columns of
 * appointments without re-running 7 separate queries.
 *
 * Cancelled / no-show appointments are omitted (they do not consume
 * capacity and should not clutter the week grid); the frontend can opt
 * back in via filters later.
 */
final class WeekViewQuery
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
    ) {}

    /**
     * @param  \DateTimeImmutable  $weekStart  First day of the week (midnight local time). The query
     *                                         pulls `[weekStart, weekStart + 7 days)`.
     * @return array<string, list<array{
     *     appointment_id: string,
     *     bay_id: ?string,
     *     primary_technician_profile_id: ?string,
     *     starts_at: \DateTimeImmutable,
     *     ends_at: \DateTimeImmutable,
     *     status: string,
     *     customer_display: ?string,
     *     appointment_number: string,
     * }>>
     */
    public function run(string $companyId, \DateTimeImmutable $weekStart): array
    {
        $from = $weekStart->setTime(0, 0, 0);
        $to = $from->modify('+7 days');

        /** @var array<string, list<array{
         *     appointment_id: string,
         *     bay_id: ?string,
         *     primary_technician_profile_id: ?string,
         *     starts_at: \DateTimeImmutable,
         *     ends_at: \DateTimeImmutable,
         *     status: string,
         *     customer_display: ?string,
         *     appointment_number: string,
         * }>> $result */
        $result = [];
        for ($i = 0; $i < 7; $i++) {
            $dayKey = $from->modify("+{$i} days")->format('Y-m-d');
            $result[$dayKey] = [];
        }

        $appointments = $this->appointments->findByCompanyInDateRange($companyId, $from, $to);
        foreach ($appointments as $appt) {
            if (in_array($appt->status->value, ['cancelled', 'no_show'], true)) {
                continue;
            }
            $dayKey = $appt->scheduled_start->format('Y-m-d');
            if (! array_key_exists($dayKey, $result)) {
                continue;
            }
            $result[$dayKey][] = [
                'appointment_id' => $appt->id,
                'bay_id' => $appt->bay_id,
                'primary_technician_profile_id' => $appt->primary_technician_profile_id,
                'starts_at' => \DateTimeImmutable::createFromInterface($appt->scheduled_start),
                'ends_at' => \DateTimeImmutable::createFromInterface($appt->scheduled_end),
                'status' => $appt->status->value,
                'customer_display' => $appt->customer_name ?? $appt->customer_phone,
                'appointment_number' => $appt->appointment_number,
            ];
        }

        // Sort each day by start time.
        foreach ($result as $day => $rows) {
            usort(
                $rows,
                fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at'],
            );
            $result[$day] = $rows;
        }

        return $result;
    }
}
