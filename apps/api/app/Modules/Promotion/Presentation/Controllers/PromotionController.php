<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Promotion\Application\DTOs\PromotionData;
use App\Modules\Promotion\Application\Services\PromotionManagementService;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Presentation\Requests\StorePromotionRequest;
use App\Modules\Promotion\Presentation\Requests\UpdatePromotionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PromotionManagementService $managementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('promotions.view');
        $companyId = $this->companyContext->requireCompanyId();

        $query = Promotion::query()
            ->forCompany($companyId);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('status')) {
            $status = PromotionStatus::tryFrom((string) $request->input('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $promotions = $query
            ->byPriority()
            ->paginate($perPage);

        return response()->json([
            'data' => $promotions->map(fn (Promotion $p) => PromotionData::fromModel($p)),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'last_page' => $promotions->lastPage(),
                'per_page' => $promotions->perPage(),
                'total' => $promotions->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize('promotions.view');
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()
            ->forCompany($companyId)
            ->findOrFail($id);

        return response()->json(['data' => PromotionData::fromModel($promotion)]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();

        $promotion = Promotion::create([
            ...$validated,
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'status' => PromotionStatus::Draft,
        ]);

        return response()->json(['data' => PromotionData::fromModel($promotion)], 201);
    }

    public function update(UpdatePromotionRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()->forCompany($companyId)->findOrFail($id);

        if ($promotion->status === PromotionStatus::Archived) {
            return response()->json([
                'error' => [
                    'code' => 'PROMOTION_ARCHIVED',
                    'message' => 'Cannot update an archived promotion.',
                ],
            ], 422);
        }

        $promotion->update($request->validated());

        return response()->json(['data' => PromotionData::fromModel($promotion)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()->forCompany($companyId)->findOrFail($id);

        if ($promotion->status === PromotionStatus::Active) {
            return response()->json([
                'error' => [
                    'code' => 'PROMOTION_ACTIVE',
                    'message' => 'Cannot delete an active promotion. Pause or archive it first.',
                ],
            ], 422);
        }

        $promotion->delete();

        return response()->json(null, 204);
    }

    public function activate(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()->forCompany($companyId)->findOrFail($id);

        try {
            $this->managementService->activate($promotion);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => PromotionData::fromModel($promotion->fresh() ?? $promotion)]);
    }

    public function pause(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()->forCompany($companyId)->findOrFail($id);

        try {
            $this->managementService->pause($promotion);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => PromotionData::fromModel($promotion->fresh() ?? $promotion)]);
    }

    public function archive(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $promotion = Promotion::query()->forCompany($companyId)->findOrFail($id);

        try {
            $this->managementService->archive($promotion);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => PromotionData::fromModel($promotion->fresh() ?? $promotion)]);
    }
}
