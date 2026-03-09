<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\RecipeCostData;
use App\Modules\Catalog\Application\DTOs\RecipeData;
use App\Modules\Catalog\Application\Services\RecipeCostCalculationService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Presentation\Requests\StoreRecipeRequest;
use App\Modules\Catalog\Presentation\Requests\UpdateRecipeRequest;
use App\Modules\Company\Services\CompanyContext;
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
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($compositeItemId);
        $recipes = Recipe::where('composite_item_id', $item->id)
            ->with(['lines.component', 'lines.unit'])
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'data' => $recipes->map(fn (Recipe $r) => RecipeData::fromModel($r)),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with(['lines.component', 'lines.unit', 'compositeItem'])
            ->findOrFail($id);

        // Verify company access
        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        return response()->json(['data' => RecipeData::fromModel($recipe)]);
    }

    public function store(StoreRecipeRequest $request, string $compositeItemId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('company_id', $companyId)->findOrFail($compositeItemId);

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
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $recipe->update($request->validated());
        $recipe->load(['lines.component', 'lines.unit']);

        return response()->json(['data' => RecipeData::fromModel($recipe)]);
    }

    public function activate(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

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
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $costData = $this->costService->calculate($recipe);

        return response()->json(['data' => $costData]);
    }
}
