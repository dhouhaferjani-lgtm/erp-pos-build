<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Identity\Domain\User;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderStatusTransition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkOrderStatusTransition>
 */
final class WorkOrderStatusTransitionFactory extends Factory
{
    /** @var class-string<WorkOrderStatusTransition> */
    protected $model = WorkOrderStatusTransition::class;

    /**
     * Default state: a Received → Diagnosed transition with no context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workOrder = WorkOrder::factory()->create();
        $user = User::factory()->create(['tenant_id' => $workOrder->tenant_id]);

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'from_status' => WorkOrderStatus::Received->value,
            'to_status' => WorkOrderStatus::Diagnosed->value,
            'reason_code' => null,
            'triggered_by_user_id' => $user->id,
            'triggered_at' => now(),
            'context' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function toStatus(WorkOrderStatus $from, WorkOrderStatus $to, ?array $context = null): self
    {
        return $this->state(fn (): array => [
            'from_status' => $from->value,
            'to_status' => $to->value,
            'context' => $context,
        ]);
    }
}
