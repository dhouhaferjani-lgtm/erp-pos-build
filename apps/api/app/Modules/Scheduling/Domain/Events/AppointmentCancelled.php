<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when an Appointment is cancelled.
 *
 * Reachable from Scheduled, Confirmed, or CheckedIn (pre-WO). After
 * CheckedIn → WO-creation, cancellation is mirrored via
 * MirrorAppointmentOnWorkOrderCancelled instead of this direct path.
 */
final readonly class AppointmentCancelled
{
    public function __construct(
        public string $appointment_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $reason_code,
        public ?string $cancelled_by_user_id,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
