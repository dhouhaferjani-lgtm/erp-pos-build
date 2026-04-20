<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Emitted on any WorkOrderLine add/update/remove after the WorkOrder reaches
 * the Approved state (audit trail).
 *
 * `change_type` is a simple string code — `added | updated | removed`.
 */
final readonly class WorkOrderLineUpdated
{
    public function __construct(
        public string $work_order_id,
        public string $line_id,
        public string $change_type,
        public \DateTimeImmutable $updated_at,
    ) {}
}
