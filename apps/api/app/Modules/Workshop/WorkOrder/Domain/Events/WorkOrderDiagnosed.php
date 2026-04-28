<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Emitted when a technician records the diagnosis (status Received → Diagnosed).
 */
final readonly class WorkOrderDiagnosed
{
    public function __construct(
        public string $work_order_id,
        public string $diagnosis,
        public \DateTimeImmutable $diagnosed_at,
    ) {}
}
