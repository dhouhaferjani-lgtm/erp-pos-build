<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Commands;

/**
 * Command for rescheduling an existing Appointment — may change the bay,
 * the start/end window, or both. Conflict detection is re-run with the
 * appointment's own id excluded so it does not self-conflict.
 */
final readonly class RescheduleAppointmentCommand
{
    public function __construct(
        public string $appointment_id,
        public ?string $new_bay_id,
        public \DateTimeImmutable $new_scheduled_start,
        public \DateTimeImmutable $new_scheduled_end,
        public ?string $rescheduled_by_user_id,
    ) {}
}
