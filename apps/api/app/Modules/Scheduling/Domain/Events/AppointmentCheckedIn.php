<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when the vehicle arrives on-site and the appointment flips to
 * CheckedIn. After this point the appointment is eligible for conversion
 * into a WorkOrder; once converted, subsequent status changes are mirrored
 * from the linked WorkOrder lifecycle (InProgress / Completed / Closed).
 */
final readonly class AppointmentCheckedIn
{
    public function __construct(
        public string $appointment_id,
        public string $tenant_id,
        public string $company_id,
        public ?string $vehicle_id,
        public ?string $checked_in_by_user_id,
        public \DateTimeImmutable $actual_arrival_at,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
