<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;

/**
 * Command input for creating a new WorkOrder at intake (Received status).
 *
 * Authoring-only. Status transitions go through TransitionStatusCommand.
 */
final readonly class CreateWorkOrderCommand
{
    public function __construct(
        public string $tenant_id,
        public string $company_id,
        public ?string $location_id,
        public WorkOrderType $type,
        public string $customer_partner_id,
        public string $vehicle_id,
        public string $opened_by_user_id,
        public ?string $primary_technician_profile_id,
        public ?int $mileage_at_intake,
        public ?string $customer_complaint,
        public ?string $internal_notes,
        public ?\DateTimeImmutable $scheduled_start_at,
        public ?\DateTimeImmutable $scheduled_end_at,
        public ?\DateTimeImmutable $promised_at,
        public string $currency,
    ) {}
}
