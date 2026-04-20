<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Queries;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Collection;

/**
 * Returns the next `$limit` active (non-cancelled / non-no-show / non-closed)
 * appointments for a given customer partner, chronologically ordered by
 * start time, only those in the future (strictly `scheduled_start >= NOW()`).
 *
 * Feeds the customer-profile panel ("your upcoming bookings") and the
 * check-in desk's "today's arrivals" widget when filtered by company.
 */
final class UpcomingAppointmentsQuery
{
    /**
     * @return Collection<int, Appointment>
     */
    public function forCustomer(string $partnerId, int $limit = 5, ?\DateTimeImmutable $now = null): Collection
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be positive.');
        }

        $reference = $now ?? new \DateTimeImmutable;

        $excludedStatuses = [
            AppointmentStatus::Cancelled->value,
            AppointmentStatus::NoShow->value,
            AppointmentStatus::Closed->value,
            AppointmentStatus::Completed->value,
        ];

        /** @var Collection<int, Appointment> $rows */
        $rows = Appointment::query()
            ->where('customer_partner_id', $partnerId)
            ->where('scheduled_start', '>=', $reference)
            ->whereNotIn('status', $excludedStatuses)
            ->orderBy('scheduled_start')
            ->limit($limit)
            ->get();

        return $rows;
    }
}
