<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

final readonly class AssignTechnicianCommand
{
    public function __construct(
        public string $work_order_id,
        public string $technician_profile_id,
        public bool $is_lead,
        public string $assigned_by_user_id,
        public ?string $notes,
    ) {}
}
