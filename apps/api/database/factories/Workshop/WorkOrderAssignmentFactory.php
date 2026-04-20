<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Identity\Domain\User;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderAssignment>
 */
final class WorkOrderAssignmentFactory extends Factory
{
    /** @var class-string<WorkOrderAssignment> */
    protected $model = WorkOrderAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workOrder = WorkOrder::factory()->create();
        $techProfile = TechnicianProfile::factory()->create([
            'tenant_id' => $workOrder->tenant_id,
            'company_id' => $workOrder->company_id,
        ]);
        $user = User::factory()->create(['tenant_id' => $workOrder->tenant_id]);

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'technician_profile_id' => $techProfile->id,
            'is_lead' => false,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'assigned_by_user_id' => $user->id,
            'notes' => null,
        ];
    }

    public function asLead(): self
    {
        return $this->state(fn (): array => ['is_lead' => true]);
    }

    public function unassigned(): self
    {
        return $this->state(fn (): array => ['unassigned_at' => now()]);
    }
}
