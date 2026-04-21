<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;

/**
 * Emitted when customer approval has been captured (status Quoted → Approved).
 * Triggers downstream parts reservation via InventoryReservationAdapter.
 */
final readonly class WorkOrderApproved
{
    public function __construct(
        public string $work_order_id,
        public ApprovalMethod $approval_method,
        public string $estimated_grand_total,
        public string $currency,
        public \DateTimeImmutable $approval_captured_at,
    ) {}
}
