<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Replenishment\Presentation\Requests\CaptureReplenishmentRequest;
use App\Modules\Replenishment\Presentation\Resources\ReplenishmentRequestResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ReplenishmentRequestController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly LocationScopeResolver $scopeResolver,
        private readonly ReplenishmentCaptureService $captureService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', ...array_column(ReplenishmentStatus::cases(), 'value')])],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['uuid'],
            'product_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $companyId = $this->companyContext->requireCompanyId();
        $query = $this->namedQuery()->where('replenishment_requests.company_id', $companyId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var list<string> $requestedLocationIds */
        $requestedLocationIds = array_values($validated['location_ids'] ?? []);
        $scopedLocationIds = $this->scopeResolver->resolve($user, $requestedLocationIds, 'replenishment.process');
        $query->whereIn('replenishment_requests.location_id', $scopedLocationIds);
        if (isset($validated['product_id'])) {
            $query->where('replenishment_requests.product_id', $validated['product_id']);
        }
        if (isset($validated['from'])) {
            $query->whereDate('replenishment_requests.first_requested_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('replenishment_requests.first_requested_at', '<=', $validated['to']);
        }

        $status = $validated['status'] ?? 'open';
        if ($status === 'open') {
            $rows = $query->open()->orderByDesc('replenishment_requests.last_requested_at')->limit(501)->get();
            $truncated = $rows->count() > 500;
            $rows = $rows->take(500)->values();

            return response()->json([
                'data' => ReplenishmentRequestResource::collection($rows)->resolve($request),
                'meta' => ['truncated' => $truncated],
            ]);
        }

        $query->where('replenishment_requests.status', $status);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query->orderByDesc('replenishment_requests.last_requested_at')->paginate($perPage);

        return response()->json([
            'data' => ReplenishmentRequestResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(CaptureReplenishmentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireTenantId();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $belongsToCompany = DB::table('locations')
            ->where('id', $validated['location_id'])
            ->where('company_id', $companyId)
            ->exists();
        if (! $belongsToCompany) {
            throw ValidationException::withMessages(['location_id' => 'Location does not belong to the active company.']);
        }

        try {
            $this->locationContext->validateLocationAccess($validated['location_id'], $companyId, $user);
        } catch (RuntimeException) {
            abort(403, 'Location is not accessible.');
        }

        $row = $this->captureService->capture(new CaptureRequestData(
            tenantId: $tenantId,
            companyId: $companyId,
            locationId: $validated['location_id'],
            productId: $validated['product_id'],
            variantId: $validated['variant_id'] ?? null,
            requestedQty: $validated['requested_qty'] ?? null,
            note: $validated['note'] ?? null,
            requestedByUserId: $user->id,
            channel: ReplenishmentChannel::Web,
        ));
        $status = $row->wasRecentlyCreated ? 201 : 200;
        $named = $this->namedQuery()->findOrFail($row->id);

        return (new ReplenishmentRequestResource($named))->response()->setStatusCode($status);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $row = ReplenishmentRequest::query()->findOrFail($id);
        abort_unless($row->company_id === $companyId, 404);
        abort_unless($row->status === ReplenishmentStatus::Pending, 422);
        abort_unless(
            $row->requested_by_user_id === $user->id || $user->can('replenishment.process'),
            403,
        );

        $row->update([
            'status' => ReplenishmentStatus::Cancelled,
            'processed_by_user_id' => $user->id,
            'processed_at' => now(),
        ]);

        return (new ReplenishmentRequestResource($this->namedQuery()->findOrFail($row->id)))->response();
    }

    /** @return Builder<ReplenishmentRequest> */
    private function namedQuery(): Builder
    {
        return ReplenishmentRequest::query()
            ->with(['product.unitOfMeasure'])
            ->leftJoin('locations', 'locations.id', '=', 'replenishment_requests.location_id')
            ->leftJoin('products', 'products.id', '=', 'replenishment_requests.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'replenishment_requests.variant_id')
            ->select([
                'replenishment_requests.*',
                'locations.name as location_name',
                'products.name as product_name',
                'product_variants.name_suffix as variant_name',
            ]);
    }
}
