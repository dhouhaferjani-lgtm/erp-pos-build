<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\RecipeLineData;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Catalog\Presentation\Requests\StoreRecipeLineRequest;
use App\Modules\Catalog\Presentation\Rules\NoCircularCompositeItemReference;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class RecipeLineController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function store(StoreRecipeLineRequest $request, string $recipeId): JsonResponse
    {
        if (! Str::isUuid($recipeId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($recipeId);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        // Validate circular references for composite_item components
        $componentType = $request->input('component_type', 'product');
        if ($componentType === ComponentType::CompositeItem->value) {
            Validator::make(
                $request->only('component_id'),
                ['component_id' => [new NoCircularCompositeItemReference($recipe->composite_item_id)]],
            )->validate();
        }

        $maxOrder = RecipeLine::where('recipe_id', $recipe->id)->max('display_order') ?? 0;

        $line = RecipeLine::create([
            ...$request->validated(),
            'recipe_id' => $recipe->id,
            'display_order' => $request->input('display_order', $maxOrder + 1),
        ]);

        $line->load(['product', 'compositeItemComponent', 'unit']);

        return response()->json(['data' => RecipeLineData::fromModel($line)], 201);
    }

    public function update(Request $request, string $recipeId, string $lineId): JsonResponse
    {
        if (! Str::isUuid($recipeId) || ! Str::isUuid($lineId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($recipeId);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $line = RecipeLine::where('recipe_id', $recipe->id)->findOrFail($lineId);

        $componentType = $request->input('component_type', $line->component_type->value);
        $existsTable = $componentType === 'composite_item' ? 'composite_items' : 'products';

        $circularRule = $componentType === ComponentType::CompositeItem->value
            ? [new NoCircularCompositeItemReference($recipe->composite_item_id)]
            : [];

        $validated = $request->validate([
            'component_type' => ['sometimes', new Enum(ComponentType::class)],
            'component_id' => ['sometimes', 'uuid', "exists:{$existsTable},id", ...$circularRule],
            'quantity' => ['sometimes', 'numeric', 'min:0.0001'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_optional' => ['sometimes', 'boolean'],
            'is_scalable' => ['sometimes', 'boolean'],
            'wastage_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $line->update($validated);
        $line->load(['product', 'compositeItemComponent', 'unit']);

        return response()->json(['data' => RecipeLineData::fromModel($line)]);
    }

    public function destroy(string $recipeId, string $lineId): JsonResponse
    {
        if (! Str::isUuid($recipeId) || ! Str::isUuid($lineId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $recipe = Recipe::with('compositeItem')->findOrFail($recipeId);

        $companyId = $this->companyContext->requireCompanyId();
        if ($recipe->compositeItem->company_id !== $companyId) {
            abort(403);
        }

        $line = RecipeLine::where('recipe_id', $recipe->id)->findOrFail($lineId);
        $line->delete();

        return response()->json(null, 204);
    }
}
