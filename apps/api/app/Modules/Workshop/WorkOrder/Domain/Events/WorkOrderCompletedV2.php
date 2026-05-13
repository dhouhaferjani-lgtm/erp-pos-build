<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Events;

/**
 * Versioned WorkOrder completion event carrying the aggregate tenant/company
 * anchor required by cross-module listeners that must reload the WorkOrder.
 */
final readonly class WorkOrderCompletedV2
{
    public function __construct(
        public string $work_order_id,
        public string $tenant_id,
        public string $company_id,
        public ?int $completion_mileage,
        public \DateTimeImmutable $completed_at,
    ) {}
}
