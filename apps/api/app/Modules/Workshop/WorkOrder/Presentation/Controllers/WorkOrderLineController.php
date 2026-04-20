<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddBundleCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\AddLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\RemoveLineCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\ReorderLinesCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UpdateLineCommand;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderLineData;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderBundleService;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderLineService;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\AddBundleRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\AddLineRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\ReorderLinesRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\UpdateLineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WorkOrderLineController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly WorkOrderRepositoryInterface $workOrders,
        private readonly WorkOrderLineService $lines,
        private readonly WorkOrderBundleService $bundles,
    ) {}

    public function store(AddLineRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $line = $this->lines->addLine(new AddLineCommand(
            work_order_id: $id,
            line_type: WorkOrderLineType::from((string) $data['line_type']),
            product_id: isset($data['product_id']) && is_string($data['product_id']) ? $data['product_id'] : null,
            service_id: isset($data['service_id']) && is_string($data['service_id']) ? $data['service_id'] : null,
            display_name: (string) $data['display_name'],
            sku_or_code: isset($data['sku_or_code']) && is_string($data['sku_or_code']) ? $data['sku_or_code'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            quantity: (string) $data['quantity'],
            unit: (string) $data['unit'],
            unit_price: (string) $data['unit_price'],
            tax_rate: (string) $data['tax_rate'],
            discount_percent: (string) $data['discount_percent'],
            labor_hours_estimated: isset($data['labor_hours_estimated']) ? (string) $data['labor_hours_estimated'] : null,
            assigned_technician_profile_id: isset($data['assigned_technician_profile_id']) && is_string($data['assigned_technician_profile_id']) ? $data['assigned_technician_profile_id'] : null,
            is_customer_supplied: (bool) ($data['is_customer_supplied'] ?? false),
        ));

        return response()->json([
            'data' => WorkOrderLineData::fromModel($line)->toArray(),
        ], 201);
    }

    public function storeBundle(AddBundleRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $lines = $this->bundles->addBundle(new AddBundleCommand(
            work_order_id: $id,
            bundle_id: (string) $data['bundle_id'],
            quantity: (string) $data['quantity'],
            vehicle_id: isset($data['vehicle_id']) && is_string($data['vehicle_id']) ? $data['vehicle_id'] : null,
        ));

        return response()->json([
            'data' => array_map(
                static fn ($l): array => WorkOrderLineData::fromModel($l)->toArray(),
                $lines,
            ),
        ], 201);
    }

    public function update(UpdateLineRequest $request, string $id, string $lineId): JsonResponse
    {
        $this->requireWorkOrder($id);
        if (! Str::isUuid($lineId)) {
            abort(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $line = $this->lines->updateLine(new UpdateLineCommand(
            work_order_id: $id,
            line_id: $lineId,
            display_name: isset($data['display_name']) && is_string($data['display_name']) ? $data['display_name'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            quantity: isset($data['quantity']) ? (string) $data['quantity'] : null,
            unit_price: isset($data['unit_price']) ? (string) $data['unit_price'] : null,
            tax_rate: isset($data['tax_rate']) ? (string) $data['tax_rate'] : null,
            discount_percent: isset($data['discount_percent']) ? (string) $data['discount_percent'] : null,
            labor_hours_actual: isset($data['labor_hours_actual']) ? (string) $data['labor_hours_actual'] : null,
            assigned_technician_profile_id: isset($data['assigned_technician_profile_id']) && is_string($data['assigned_technician_profile_id']) ? $data['assigned_technician_profile_id'] : null,
            is_completed: isset($data['is_completed']) ? (bool) $data['is_completed'] : null,
        ));

        return response()->json([
            'data' => WorkOrderLineData::fromModel($line)->toArray(),
        ]);
    }

    public function destroy(Request $request, string $id, string $lineId): JsonResponse
    {
        if (! $request->user()?->can('work-orders.update')) {
            abort(403);
        }
        $this->requireWorkOrder($id);
        if (! Str::isUuid($lineId)) {
            abort(404);
        }

        $this->lines->removeLine(new RemoveLineCommand(
            work_order_id: $id,
            line_id: $lineId,
        ));

        return response()->json(null, 204);
    }

    public function reorder(ReorderLinesRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        /** @var list<string> $lineIds */
        $lineIds = [];
        if (isset($data['ordered_line_ids']) && is_array($data['ordered_line_ids'])) {
            foreach ($data['ordered_line_ids'] as $lid) {
                if (is_string($lid)) {
                    $lineIds[] = $lid;
                }
            }
        }

        $this->lines->reorderLines(new ReorderLinesCommand(
            work_order_id: $id,
            ordered_line_ids: $lineIds,
        ));

        return response()->json(null, 204);
    }

    private function requireWorkOrder(string $id): void
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $companyId = $this->companyContext->requireCompanyId();
        $wo = $this->workOrders->findById($id);
        if ($wo === null || $wo->company_id !== $companyId) {
            abort(404);
        }
    }
}
