<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when an Appointment is converted to a WorkOrder on check-in.
 *
 * The appointment remains linked via `scheduling_appointments.work_order_id`;
 * subsequent status transitions (InProgress / Completed / Cancelled /
 * Closed) are mirrored from the WorkOrder's own events by the 4
 * MirrorAppointmentOn* listeners — the Scheduling module does NOT re-emit
 * these statuses itself after conversion.
 */
final readonly class AppointmentConvertedToWorkOrder
{
    public function __construct(
        public string $appointment_id,
        public string $work_order_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $converted_by_user_id,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
