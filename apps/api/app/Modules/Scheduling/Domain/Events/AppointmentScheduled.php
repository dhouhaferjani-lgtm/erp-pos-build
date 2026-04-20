<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Events;

/**
 * Emitted when a new Appointment is created (status = Scheduled).
 *
 * Subscribers: future Notification module (customer booking confirmation
 * email/SMS), analytics event log. Event is immutable — once persisted /
 * dispatched its shape is frozen per AutoERP rule 8 (events are immutable
 * forever; create `AppointmentScheduledV2` rather than mutating).
 */
final readonly class AppointmentScheduled
{
    public function __construct(
        public string $appointment_id,
        public string $tenant_id,
        public string $company_id,
        public string $location_id,
        public ?string $bay_id,
        public ?string $customer_partner_id,
        public ?string $vehicle_id,
        public \DateTimeImmutable $scheduled_start,
        public \DateTimeImmutable $scheduled_end,
        public string $source,
        public \DateTimeImmutable $occurred_at,
    ) {}
}
