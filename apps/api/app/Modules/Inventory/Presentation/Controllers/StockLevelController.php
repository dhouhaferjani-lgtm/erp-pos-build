<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\StockLevelData;
use App\Modules\Inventory\Domain\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StockLevelController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $locationScopeResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'],
            'location_ids.*' => ['string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $requestedLocationIds = array_key_exists('location_ids', $validated)
            ? array_values($validated['location_ids'])
            : (($validated['location_id'] ?? null) !== null ? [(string) $validated['location_id']] : []);
        $locationIds = $this->locationScopeResolver->resolve($user, $requestedLocationIds);

        // Both predicates required: tenant_id alone leaks same-tenant
        // cross-company stock data when a user with multi-company
        // membership selects company A but the query returns company B
        // rows that share the tenant. (api.inventory Codex round-1
        // Finding 1.)
        $query = StockLevel::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['product.unitOfMeasure', 'location']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $query->whereIn('location_id', $locationIds);

        $stockLevels = $query->orderBy('created_at', 'desc')->paginate(20);

        $data = $stockLevels->getCollection()
            ->map(fn (StockLevel $level): StockLevelData => StockLevelData::fromModel($level));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $stockLevels->currentPage(),
                'last_page' => $stockLevels->lastPage(),
                'per_page' => $stockLevels->perPage(),
                'total' => $stockLevels->total(),
            ],
        ]);
    }

    public function show(Request $request, string $productId, string $locationId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        // Both predicates required (see index() comment).
        $stockLevel = StockLevel::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->with(['product', 'location'])
            ->firstOrFail();

        return response()->json([
            'data' => StockLevelData::fromModel($stockLevel),
        ]);
    }
}
