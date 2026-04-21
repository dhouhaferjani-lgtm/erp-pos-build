<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when an Appointment's scheduled window or bay is changed.
 *
 * Distinct from AppointmentScheduled — fires on reassign-bay /
 * reschedule-time flows. The previous/new values allow downstream services
 * (notification, availability recalculation) to react idempotently.
 */
final readonly class AppointmentRescheduled
{
    public function __construct(
        public string $appointment_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $previous_bay_id,
        public ?string $new_bay_id,
        public \DateTimeImmutable $previous_scheduled_start,
        public \DateTimeImmutable $previous_scheduled_end,
        public \DateTimeImmutable $new_scheduled_start,
        public \DateTimeImmutable $new_scheduled_end,
        public ?string $rescheduled_by_user_id,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
