<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\WorkOrder\Application\Commands\AssignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\SetPrimaryTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UnassignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderAssignmentData;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderAssignmentService;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\AssignTechnicianRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\SetPrimaryTechnicianRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WorkOrderAssignmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly WorkOrderRepositoryInterface $workOrders,
        private readonly WorkOrderAssignmentService $assignments,
    ) {}

    public function store(AssignTechnicianRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);

        $userId = (string) $request->user()?->id;
        if ($userId === '') {
            abort(401);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment = $this->assignments->assign(new AssignTechnicianCommand(
            work_order_id: $id,
            technician_profile_id: (string) $data['technician_profile_id'],
            is_lead: (bool) ($data['is_lead'] ?? false),
            assigned_by_user_id: $userId,
            notes: isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null,
        ));

        return response()->json([
            'data' => WorkOrderAssignmentData::fromModel($assignment)->toArray(),
        ], 201);
    }

    public function destroy(Request $request, string $id, string $assignmentId): JsonResponse
    {
        if (! $request->user()?->can('work-orders.assign')) {
            abort(403);
        }
        $this->requireWorkOrder($id);
        if (! Str::isUuid($assignmentId)) {
            abort(404);
        }

        $this->assignments->unassign(new UnassignTechnicianCommand(
            work_order_id: $id,
            assignment_id: $assignmentId,
        ));

        return response()->json(null, 204);
    }

    public function setPrimary(SetPrimaryTechnicianRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment = $this->assignments->setPrimary(new SetPrimaryTechnicianCommand(
            work_order_id: $id,
            technician_profile_id: (string) $data['technician_profile_id'],
        ));

        return response()->json([
            'data' => WorkOrderAssignmentData::fromModel($assignment)->toArray(),
        ]);
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
