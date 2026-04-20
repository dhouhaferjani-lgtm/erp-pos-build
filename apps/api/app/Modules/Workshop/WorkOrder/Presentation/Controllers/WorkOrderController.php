<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\WorkOrder\Application\Commands\CreateWorkOrderCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UpdateWorkOrderHeaderCommand;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderData;
use App\Modules\Workshop\WorkOrder\Application\DTOs\WorkOrderListItemData;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderAuthoringService;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\CreateWorkOrderRequest;
use App\Modules\Workshop\WorkOrder\Presentation\Requests\UpdateWorkOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resource controller for the WorkOrder aggregate: list + show + create + update.
 *
 * Financial fields on the detail payload are redacted when the caller lacks
 * `work-orders.view_financials` — the response includes `totals: null` and
 * line-level money fields null to preserve parity with Spec §9.
 */
final class WorkOrderController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly WorkOrderRepositoryInterface $workOrders,
        private readonly WorkOrderAuthoringService $authoring,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reqUser = $request->user();
        if ($reqUser === null || ! $reqUser->can('work-orders.view')) {
            abort(403);
        }

        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();

        $perPage = min((int) $request->input('per_page', 25), 100);

        $filters = [];
        $statusRaw = $request->query('status');
        if (is_string($statusRaw) && $statusRaw !== '') {
            $status = WorkOrderStatus::tryFrom($statusRaw);
            if ($status !== null) {
                $filters['status'] = $status;
            }
        }
        $vehicleId = $request->query('vehicle_id');
        if (is_string($vehicleId) && Str::isUuid($vehicleId)) {
            $filters['vehicle_id'] = $vehicleId;
        }
        $customerId = $request->query('customer_partner_id');
        if (is_string($customerId) && Str::isUuid($customerId)) {
            $filters['customer_partner_id'] = $customerId;
        }
        $techId = $request->query('primary_technician_profile_id');
        if (is_string($techId) && Str::isUuid($techId)) {
            $filters['primary_technician_profile_id'] = $techId;
        }

        $page = $this->workOrders->paginate(
            tenantId: $company->tenant_id,
            companyId: $companyId,
            filters: $filters,
            perPage: $perPage,
        );

        $user = $request->user();
        $canViewFinancials = $user !== null ? (bool) $user->can('work-orders.view_financials') : false;

        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($page->items() as $wo) {
            $item = WorkOrderListItemData::fromModel($wo)->toArray();
            if (! $canViewFinancials) {
                $item['estimated_grand_total'] = null;
                $item['actual_grand_total'] = null;
            }
            $items[] = $item;
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $reqUser = $request->user();
        if ($reqUser === null || ! $reqUser->can('work-orders.view')) {
            abort(403);
        }
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $wo = $this->workOrders->findById($id);
        if ($wo === null) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        if ($wo->company_id !== $companyId) {
            abort(404);
        }

        $user = $request->user();
        $canViewFinancials = $user !== null ? (bool) $user->can('work-orders.view_financials') : false;
        $payload = $this->serializeDetail($wo, $canViewFinancials);

        return response()->json(['data' => $payload]);
    }

    public function store(CreateWorkOrderRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $this->companyContext->requireCompanyId();
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }
        $userId = (string) $user->id;

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $wo = $this->authoring->create(new CreateWorkOrderCommand(
            tenant_id: $company->tenant_id,
            company_id: $companyId,
            location_id: isset($data['location_id']) && is_string($data['location_id']) ? $data['location_id'] : null,
            type: WorkOrderType::from((string) $data['type']),
            customer_partner_id: (string) $data['customer_partner_id'],
            vehicle_id: (string) $data['vehicle_id'],
            opened_by_user_id: $userId,
            primary_technician_profile_id: isset($data['primary_technician_profile_id']) && is_string($data['primary_technician_profile_id']) ? $data['primary_technician_profile_id'] : null,
            mileage_at_intake: isset($data['mileage_at_intake']) ? (int) $data['mileage_at_intake'] : null,
            customer_complaint: isset($data['customer_complaint']) && is_string($data['customer_complaint']) ? $data['customer_complaint'] : null,
            internal_notes: isset($data['internal_notes']) && is_string($data['internal_notes']) ? $data['internal_notes'] : null,
            scheduled_start_at: isset($data['scheduled_start_at']) && is_string($data['scheduled_start_at']) ? new \DateTimeImmutable($data['scheduled_start_at']) : null,
            scheduled_end_at: isset($data['scheduled_end_at']) && is_string($data['scheduled_end_at']) ? new \DateTimeImmutable($data['scheduled_end_at']) : null,
            promised_at: isset($data['promised_at']) && is_string($data['promised_at']) ? new \DateTimeImmutable($data['promised_at']) : null,
            currency: (string) $data['currency'],
        ));

        $canViewFinancials = (bool) $user->can('work-orders.view_financials');

        return response()->json([
            'data' => $this->serializeDetail($wo->fresh(['lines', 'assignments', 'statusTransitions']) ?? $wo, $canViewFinancials),
        ], 201);
    }

    public function update(UpdateWorkOrderRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $wo = $this->workOrders->findById($id);
        if ($wo === null || $wo->company_id !== $companyId) {
            abort(404);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $updated = $this->authoring->updateHeader(new UpdateWorkOrderHeaderCommand(
            work_order_id: $id,
            primary_technician_profile_id: isset($data['primary_technician_profile_id']) && is_string($data['primary_technician_profile_id']) ? $data['primary_technician_profile_id'] : null,
            diagnosis: isset($data['diagnosis']) && is_string($data['diagnosis']) ? $data['diagnosis'] : null,
            internal_notes: isset($data['internal_notes']) && is_string($data['internal_notes']) ? $data['internal_notes'] : null,
            scheduled_start_at: isset($data['scheduled_start_at']) && is_string($data['scheduled_start_at']) ? new \DateTimeImmutable($data['scheduled_start_at']) : null,
            scheduled_end_at: isset($data['scheduled_end_at']) && is_string($data['scheduled_end_at']) ? new \DateTimeImmutable($data['scheduled_end_at']) : null,
            promised_at: isset($data['promised_at']) && is_string($data['promised_at']) ? new \DateTimeImmutable($data['promised_at']) : null,
        ));

        $user = $request->user();
        $canViewFinancials = $user !== null ? (bool) $user->can('work-orders.view_financials') : false;

        return response()->json([
            'data' => $this->serializeDetail($updated->fresh(['lines', 'assignments', 'statusTransitions']) ?? $updated, $canViewFinancials),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(WorkOrder $wo, bool $canViewFinancials): array
    {
        $wo->loadMissing(['lines', 'assignments', 'statusTransitions', 'customer', 'vehicle', 'primaryTechnician.user']);

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

        return $payload;
    }
}
