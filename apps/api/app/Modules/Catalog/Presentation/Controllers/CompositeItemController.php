<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\CompositeItemData;
use App\Modules\Catalog\Application\Services\CompositeItemAvailabilityService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Presentation\Requests\StoreCompositeItemRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateCompositeItemRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CompositeItemController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CompositeItemAvailabilityService $availabilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = CompositeItem::query()
            ->where('company_id', $companyId)
            ->with(['category', 'activeRecipe', 'variants']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('vertical_type')) {
            $query->where('vertical_type', $request->input('vertical_type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $items = $query->orderBy('display_order')->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $items->map(fn (CompositeItem $item) => CompositeItemData::fromModel($item)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)
            ->with(['category', 'activeRecipe.lines.product', 'activeRecipe.lines.compositeItemComponent', 'activeRecipe.lines.unit', 'variants', 'modifierGroups.modifiers'])
            ->findOrFail($id);

        return response()->json(['data' => CompositeItemData::fromModel($item)]);
    }

    public function store(StoreCompositeItemRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $item = CompositeItem::create([
            ...$request->validated(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);

        $item->load(['category']);

        return response()->json(['data' => CompositeItemData::fromModel($item)], 201);
    }

    public function update(UpdateCompositeItemRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($id);
        $item->update($request->validated());
        $item->load(['category', 'activeRecipe.lines.product', 'activeRecipe.lines.compositeItemComponent', 'variants', 'modifierGroups.modifiers']);

        return response()->json(['data' => CompositeItemData::fromModel($item)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($id);
        $item->delete();

        return response()->json(null, 204);
    }

    public function duplicate(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)
            ->with(['activeRecipe.lines', 'variants'])
            ->findOrFail($id);

        $newItem = $item->replicate(['id', 'default_recipe_id']);
        $newItem->code = $item->code . '-copy-' . Str::random(4);
        $newItem->name = $item->name . ' (Copy)';
        $newItem->save();

        // Duplicate active recipe and lines
        if ($item->activeRecipe !== null) {
            $newRecipe = $item->activeRecipe->replicate(['id']);
            $newRecipe->composite_item_id = $newItem->id;
            $newRecipe->save();

            foreach ($item->activeRecipe->lines as $line) {
                $newLine = $line->replicate(['id']);
                $newLine->recipe_id = $newRecipe->id;
                $newLine->save();
            }

            $newItem->update(['default_recipe_id' => $newRecipe->id]);
        }

        // Duplicate variants
        foreach ($item->variants as $variant) {
            $newVariant = $variant->replicate(['id']);
            $newVariant->composite_item_id = $newItem->id;
            $newVariant->code = $variant->code . '-copy';
            $newVariant->save();
        }

        $newItem->load(['category', 'activeRecipe.lines.product', 'activeRecipe.lines.compositeItemComponent', 'variants']);

        return response()->json(['data' => CompositeItemData::fromModel($newItem)], 201);
    }

    public function checkAvailability(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $locationId = $request->input('location_id');
        if (! is_string($locationId) || ! Str::isUuid($locationId)) {
            return response()->json(['message' => 'location_id is required and must be a valid UUID'], 422);
        }

        $item = CompositeItem::where('company_id', $companyId)
            ->with(['activeRecipe.lines.product', 'activeRecipe.lines.compositeItemComponent'])
            ->findOrFail($id);

        $availability = $this->availabilityService->checkAvailability($item, $locationId);

        return response()->json(['data' => $availability]);
    }
}
