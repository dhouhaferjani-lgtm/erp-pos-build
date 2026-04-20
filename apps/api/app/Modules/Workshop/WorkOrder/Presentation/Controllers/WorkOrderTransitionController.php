<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\WorkOrder\Application\Commands\CaptureApprovalCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderData;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\ApprovalMethod;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\StaleWorkOrderException;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderTransitionException;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\CancelRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\CaptureApprovalRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\CompleteRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\TransitionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Status transition endpoints. All business-rule enforcement (allowed
 * transitions, stale-check, side effects) lives in WorkOrderTransitionService;
 * this controller converts HTTP payloads to commands and maps exceptions to
 * 409 / 422 responses.
 */
final class WorkOrderTransitionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly WorkOrderRepositoryInterface $workOrders,
        private readonly WorkOrderTransitionService $transitions,
    ) {}

    public function transition(TransitionRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);
        $userId = $this->userId($request);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $wo = $this->transitions->transition(new TransitionStatusCommand(
                work_order_id: $id,
                to_status: WorkOrderStatus::from((string) $data['to_status']),
                reason_code: isset($data['reason_code']) && is_string($data['reason_code']) ? $data['reason_code'] : null,
                triggered_by_user_id: $userId,
                occurred_at: new \DateTimeImmutable,
                context: isset($data['context']) && is_array($data['context']) ? $data['context'] : null,
                expected_updated_at: isset($data['expected_updated_at']) && is_string($data['expected_updated_at']) ? new \DateTimeImmutable($data['expected_updated_at']) : null,
            ));
        } catch (StaleWorkOrderException $e) {
            return $this->conflict($e);
        } catch (WorkOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_TRANSITION',
            ], 422);
        }

        return $this->detail($request, $wo);
    }

    public function approve(CaptureApprovalRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);
        $userId = $this->userId($request);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $wo = $this->transitions->captureApproval(new CaptureApprovalCommand(
                work_order_id: $id,
                approval_method: ApprovalMethod::from((string) $data['approval_method']),
                approval_captured_by_user_id: $userId,
                approval_reference: isset($data['approval_reference']) && is_string($data['approval_reference']) ? $data['approval_reference'] : null,
                approval_captured_at: isset($data['approval_captured_at']) && is_string($data['approval_captured_at']) ? new \DateTimeImmutable($data['approval_captured_at']) : new \DateTimeImmutable,
                expected_updated_at: isset($data['expected_updated_at']) && is_string($data['expected_updated_at']) ? new \DateTimeImmutable($data['expected_updated_at']) : null,
            ));
        } catch (StaleWorkOrderException $e) {
            return $this->conflict($e);
        } catch (WorkOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_TRANSITION',
            ], 422);
        }

        return $this->detail($request, $wo);
    }

    public function cancel(CancelRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);
        $userId = $this->userId($request);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $wo = $this->transitions->transition(new TransitionStatusCommand(
                work_order_id: $id,
                to_status: WorkOrderStatus::Cancelled,
                reason_code: (string) $data['reason_code'],
                triggered_by_user_id: $userId,
                occurred_at: new \DateTimeImmutable,
                context: isset($data['note']) && is_string($data['note']) ? ['note' => $data['note']] : null,
                expected_updated_at: isset($data['expected_updated_at']) && is_string($data['expected_updated_at']) ? new \DateTimeImmutable($data['expected_updated_at']) : null,
            ));
        } catch (StaleWorkOrderException $e) {
            return $this->conflict($e);
        } catch (WorkOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_TRANSITION',
            ], 422);
        }

        return $this->detail($request, $wo);
    }

    public function complete(CompleteRequest $request, string $id): JsonResponse
    {
        $this->requireWorkOrder($id);
        $userId = $this->userId($request);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $context = isset($data['completion_mileage']) ? ['completion_mileage' => (int) $data['completion_mileage']] : null;

        try {
            $wo = $this->transitions->transition(new TransitionStatusCommand(
                work_order_id: $id,
                to_status: WorkOrderStatus::Completed,
                reason_code: null,
                triggered_by_user_id: $userId,
                occurred_at: new \DateTimeImmutable,
                context: $context,
                expected_updated_at: isset($data['expected_updated_at']) && is_string($data['expected_updated_at']) ? new \DateTimeImmutable($data['expected_updated_at']) : null,
            ));
        } catch (StaleWorkOrderException $e) {
            return $this->conflict($e);
        } catch (WorkOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_TRANSITION',
            ], 422);
        }

        return $this->detail($request, $wo);
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

    private function userId(Request $request): string
    {
        $id = (string) $request->user()?->id;
        if ($id === '') {
            abort(401);
        }

        return $id;
    }

    private function conflict(StaleWorkOrderException $e): JsonResponse
    {
        return response()->json([
            'code' => 'WORK_ORDER_STALE',
            'message' => $e->getMessage(),
            'work_order_id' => $e->workOrderId,
            'expected_updated_at' => $e->expectedUpdatedAt->format(\DateTimeInterface::ATOM),
            'current_updated_at' => $e->currentUpdatedAt->format(\DateTimeInterface::ATOM),
        ], 409);
    }

    private function detail(Request $request, WorkOrder $wo): JsonResponse
    {
        $wo->loadMissing(['lines', 'assignments', 'statusTransitions', 'customer', 'vehicle', 'primaryTechnician.user']);

        $canViewFinancials = (bool) ($request->user()?->can('work-orders.view_financials'));
        $payload = WorkOrderData::fromModel($wo)->toArray();
        if (! $canViewFinancials) {
            $payload['estimated_totals'] = null;
            $payload['actual_totals'] = null;
            if (isset($payload['lines']) && is_array($payload['lines'])) {
                foreach ($payload['lines'] as $i => $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $line['unit_price'] = null;
                    $line['tax_rate'] = null;
                    $line['discount_percent'] = null;
                    $line['line_total_excl_tax'] = null;
                    $line['line_total_tax'] = null;
                    $line['line_total_incl_tax'] = null;
                    $payload['lines'][$i] = $line;
                }
            }
        }

        return response()->json(['data' => $payload]);
    }
}
