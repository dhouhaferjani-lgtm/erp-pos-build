<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;

/**
 * Emitted when a WorkOrder is first created (status = Received).
 */
final readonly class WorkOrderCreated
{
    public function __construct(
        public string $work_order_id,
        public string $vehicle_id,
        public string $customer_partner_id,
        public WorkOrderType $type,
        public \DateTimeImmutable $created_at,
    ) {}
}
