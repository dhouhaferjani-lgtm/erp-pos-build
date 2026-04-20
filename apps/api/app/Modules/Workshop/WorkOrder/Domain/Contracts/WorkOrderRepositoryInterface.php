<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Contracts;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Domain contract for WorkOrder persistence. Implemented by
 * `Infrastructure\Persistence\EloquentWorkOrderRepository` (Task 8).
 *
 * Narrower than raw Eloquent: exposes exactly the reads + writes the
 * application services (`WorkOrderAuthoringService`,
 * `WorkOrderTransitionService`, etc.) need. Keeps the Application layer
 * decoupled from Eloquent for testability.
 */
interface WorkOrderRepositoryInterface
{
    public function findById(string $id): ?WorkOrder;

    /**
     * Lock the row for update (`SELECT ... FOR UPDATE`). Used by
     * WorkOrderTransitionService to serialize concurrent transitions.
     */
    public function findForUpdate(string $id): ?WorkOrder;

    public function save(WorkOrder $workOrder): WorkOrder;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkOrder>
     */
    public function paginate(
        string $tenantId,
        string $companyId,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator;

    /**
     * @param  list<WorkOrderStatus>  $statuses
     * @return list<WorkOrder>
     */
    public function listByStatuses(string $tenantId, string $companyId, array $statuses): array;
}
