<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\RecipeData;
use App\Modules\Catalog\Application\Services\RecipeCostCalculationService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Presentation\Requests\StoreRecipeRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateRecipeRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RecipeCostCalculationService $costService,
    ) {}

    public function index(string $compositeItemId): JsonResponse
    {
        // api.catalog.019 round-2: ::query() + tenant_id predicate on CompositeItem lookup.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);
        $recipes = Recipe::where('composite_item_id', $item->id)
            ->with(['lines.product', 'lines.compositeItemComponent', 'lines.unit'])
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'data' => $recipes->map(fn (Recipe $r) => RecipeData::fromModel($r)),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        // api.catalog.019 round-2: whereHas scopes Recipe via compositeItem
        // (recipes carries no tenant_id/company_id directly — ownership is
        // through composite_item_id FK). Single-query enforcement at DB level.
        // whereRaw is required because the generic Builder<Model> closure does
        // not narrow to CompositeItem at PHPStan level 8 (same pattern as
        // ModifierController::update).
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with(['lines.product', 'lines.compositeItemComponent', 'lines.unit', 'compositeItem'])
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

        return response()->json(['data' => RecipeData::fromModel($recipe)]);
    }

    public function store(StoreRecipeRequest $request, string $compositeItemId): JsonResponse
    {
        // api.catalog.019 round-2: ::query() + tenant_id predicate on CompositeItem lookup.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);

        // Auto-increment version
        $maxVersion = Recipe::where('composite_item_id', $item->id)->max('version') ?? 0;

        $isFirstRecipe = $maxVersion === 0;

        $recipe = Recipe::create([
            ...$request->validated(),
            'composite_item_id' => $item->id,
            'version' => $maxVersion + 1,
            'is_active' => $isFirstRecipe,
        ]);

        if ($isFirstRecipe) {
            $item->update(['default_recipe_id' => $recipe->id]);
        }

        return response()->json(['data' => RecipeData::fromModel($recipe)], 201);
    }

    public function update(UpdateRecipeRequest $request, string $id): JsonResponse
    {
        // api.catalog.019 round-2: whereHas scopes Recipe via compositeItem.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

        $recipe->update($request->validated());
        $recipe->load(['lines.product', 'lines.compositeItemComponent', 'lines.unit']);

        return response()->json(['data' => RecipeData::fromModel($recipe)]);
    }

    public function activate(string $id): JsonResponse
    {
        // api.catalog.019 round-2: whereHas scopes Recipe via compositeItem.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

        // Deactivate all other recipes for this composite item
        Recipe::where('composite_item_id', $recipe->composite_item_id)
            ->where('id', '!=', $recipe->id)
            ->update(['is_active' => false]);

        $recipe->update(['is_active' => true]);

        // Update composite item's default_recipe_id
        $recipe->compositeItem->update(['default_recipe_id' => $recipe->id]);

        return response()->json(['data' => RecipeData::fromModel($recipe)]);
    }

    public function calculateCost(string $id): JsonResponse
    {
        // api.catalog.019 round-2: whereHas scopes Recipe via compositeItem.
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')
            ->whereHas('compositeItem', function (Builder $q) use ($company): void {
                $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                    ->whereRaw('company_id = ?', [$company->id]);
            })
            ->findOrFail($id);

        $costData = $this->costService->calculate($recipe);

        return response()->json(['data' => $costData]);
    }
}
