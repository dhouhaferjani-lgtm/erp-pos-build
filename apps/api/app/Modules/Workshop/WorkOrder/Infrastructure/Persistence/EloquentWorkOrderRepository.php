<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Persistence;

use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentWorkOrderRepository implements WorkOrderRepositoryInterface
{
    public function findById(string $id): ?WorkOrder
    {
        return WorkOrder::query()->find($id);
    }

    public function findForUpdate(string $id): ?WorkOrder
    {
        return WorkOrder::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function save(WorkOrder $workOrder): WorkOrder
    {
        $workOrder->save();

        return $workOrder;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkOrder>
     */
    public function paginate(
        string $tenantId,
        string $companyId,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = WorkOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator<int, WorkOrder> $page */
        $page = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $page;
    }

    /**
     * @param  list<WorkOrderStatus>  $statuses
     * @return list<WorkOrder>
     */
    public function listByStatuses(string $tenantId, string $companyId, array $statuses): array
    {
        $statusValues = array_map(static fn (WorkOrderStatus $s): string => $s->value, $statuses);

        /** @var list<WorkOrder> $result */
        $result = WorkOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('status', $statusValues)
            ->orderBy('created_at', 'desc')
            ->get()
            ->values()
            ->all();

        return $result;
    }

    /**
     * @param  Builder<WorkOrder>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status']) && $filters['status'] instanceof WorkOrderStatus) {
            $query->where('status', $filters['status']->value);
        }
        if (isset($filters['vehicle_id']) && is_string($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }
        if (isset($filters['customer_partner_id']) && is_string($filters['customer_partner_id'])) {
            $query->where('customer_partner_id', $filters['customer_partner_id']);
        }
        if (isset($filters['primary_technician_profile_id']) && is_string($filters['primary_technician_profile_id'])) {
            $query->where('primary_technician_profile_id', $filters['primary_technician_profile_id']);
        }
    }
}
