<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Contracts;

use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;

/**
 * Domain contract for WorkOrderLine persistence. Implemented by
 * `Infrastructure\Persistence\EloquentWorkOrderLineRepository` (Task 8).
 */
interface WorkOrderLineRepositoryInterface
{
    public function findById(string $id): ?WorkOrderLine;

    public function save(WorkOrderLine $line): WorkOrderLine;

    public function delete(string $id): void;

    /**
     * @return list<WorkOrderLine>
     */
    public function listForWorkOrder(string $workOrderId): array;

    /**
     * @return list<WorkOrderLine>
     */
    public function listPartLinesForWorkOrder(string $workOrderId): array;
}
