<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Persistence;

use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;

final readonly class EloquentWorkOrderLineRepository implements WorkOrderLineRepositoryInterface
{
    public function findById(string $id): ?WorkOrderLine
    {
        return WorkOrderLine::query()->find($id);
    }

    public function save(WorkOrderLine $line): WorkOrderLine
    {
        $line->save();

        return $line;
    }

    public function delete(string $id): void
    {
        WorkOrderLine::query()->whereKey($id)->delete();
    }

    /**
     * @return list<WorkOrderLine>
     */
    public function listForWorkOrder(string $workOrderId): array
    {
        /** @var list<WorkOrderLine> $result */
        $result = WorkOrderLine::query()
            ->where('work_order_id', $workOrderId)
            ->orderBy('display_order')
            ->get()
            ->values()
            ->all();

        return $result;
    }

    /**
     * @return list<WorkOrderLine>
     */
    public function listPartLinesForWorkOrder(string $workOrderId): array
    {
        /** @var list<WorkOrderLine> $result */
        $result = WorkOrderLine::query()
            ->where('work_order_id', $workOrderId)
            ->where('line_type', WorkOrderLineType::Part->value)
            ->orderBy('display_order')
            ->get()
            ->values()
            ->all();

        return $result;
    }
}
