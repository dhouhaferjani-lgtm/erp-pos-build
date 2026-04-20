<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderStatusTransition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a status-transition audit row.
 */
#[TypeScript]
final class WorkOrderStatusTransitionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public string $id,
        public string $work_order_id,
        public ?WorkOrderStatus $from_status,
        public WorkOrderStatus $to_status,
        public ?string $reason_code,
        public ?string $triggered_by_user_id,
        public string $triggered_at,
        public ?array $context,
    ) {}

    public static function fromModel(WorkOrderStatusTransition $t): self
    {
        return new self(
            id: $t->id,
            work_order_id: $t->work_order_id,
            from_status: $t->from_status,
            to_status: $t->to_status,
            reason_code: $t->reason_code,
            triggered_by_user_id: $t->triggered_by_user_id,
            triggered_at: $t->triggered_at->toIso8601String(),
            context: $t->context,
        );
    }
}
