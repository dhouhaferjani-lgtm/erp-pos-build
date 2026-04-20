<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Commands;

use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;

/**
 * Command object for creating a new Appointment.
 *
 * Planned services are carried as a list of `{service_ref_type, service_ref_id}`
 * pairs — the persistence layer writes one `scheduling_appointment_services`
 * row per entry. The authoring service validates bay overlap BEFORE persisting.
 */
final readonly class BookAppointmentCommand
{
    /**
     * @param  list<array{service_ref_type: string, service_ref_id: string, display_name: string, estimated_duration_minutes: int, estimated_price?: string, display_order?: int}>  $planned_services
     */
    public function __construct(
        public string $tenant_id,
        public string $company_id,
        public string $location_id,
        public ?string $bay_id,
        public ?string $primary_technician_profile_id,
        public ?string $customer_partner_id,
        public ?string $vehicle_id,
        public ?string $customer_name,
        public ?string $customer_phone,
        public ?string $customer_email,
        public ?string $vehicle_plate,
        public ?string $vehicle_description,
        public AppointmentType $appointment_type,
        public WaitType $wait_type,
        public \DateTimeImmutable $scheduled_start,
        public \DateTimeImmutable $scheduled_end,
        public int $estimated_duration_minutes,
        public AppointmentSource $source,
        public array $planned_services,
        public ?string $services_summary = null,
        public ?string $customer_notes = null,
        public ?string $internal_notes = null,
    ) {}
}
