<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a technician-to-WorkOrder assignment row.
 */
#[TypeScript]
final class WorkOrderAssignmentData extends Data
{
    public function __construct(
        public string $id,
        public string $work_order_id,
        public string $technician_profile_id,
        public ?string $technician_display_name,
        public bool $is_lead,
        public string $assigned_at,
        public ?string $unassigned_at,
        public string $assigned_by_user_id,
        public ?string $notes,
    ) {}

    public static function fromModel(WorkOrderAssignment $a): self
    {
        return new self(
            id: $a->id,
            work_order_id: $a->work_order_id,
            technician_profile_id: $a->technician_profile_id,
            technician_display_name: $a->technicianProfile->user->name ?? null,
            is_lead: $a->is_lead,
            assigned_at: $a->assigned_at->toIso8601String(),
            unassigned_at: $a->unassigned_at?->toIso8601String(),
            assigned_by_user_id: $a->assigned_by_user_id,
            notes: $a->notes,
        );
    }
}
