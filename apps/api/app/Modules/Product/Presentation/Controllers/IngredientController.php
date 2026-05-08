<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\IngredientData;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\IngredientTranslation;
use App\Shared\Architecture\CrossTenantRoute;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    /**
     * List all ingredients with sorting and filtering.
     */
    #[CrossTenantRoute(reason: 'Ingredients catalog: global reference data (the ingredients table has no tenant_id column — every tenant queries the same shared regulatory ingredient/allergen catalog). Permission-gated by SetPermissionsTeam middleware on the route group, but cross-tenant by data design.')]
    public function index(Request $request): JsonResponse
    {
        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            ['slug', 'is_allergen', 'regulatory_status', 'created_at'],
            'created_at',
            'desc'
        );

        // Build query
        $query = Ingredient::query();

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Get per_page parameter
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Paginate
        $paginator = $query->paginate($perPage);

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, IngredientData::class)
        );
    }

    /**
     * Get a single ingredient by ID.
     */
    #[CrossTenantRoute(reason: 'Ingredients catalog: reads a single global ingredient record from the platform-shared catalog (no tenant_id column on ingredients table).')]
    public function show(Request $request, string $id): JsonResponse
    {
        $ingredient = Ingredient::find($id);

        if (! $ingredient) {
            return response()->json([
                'error' => [
                    'code' => 'INGREDIENT_NOT_FOUND',
                    'message' => 'Ingredient not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => IngredientData::fromModel($ingredient),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new ingredient with translations.
     */
    #[CrossTenantRoute(reason: 'Ingredients catalog: creates a new entry in the platform-shared regulatory ingredient catalog (no tenant_id on ingredients/ingredient_translations tables); every tenant sees the new record. Permission-gated.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255', 'unique:ingredients,slug'],
            'cas_number' => ['nullable', 'string', 'max:50'],
            'is_allergen' => ['required', 'boolean'],
            'allergen_code' => ['nullable', 'string', 'max:20'],
            'regulatory_status' => ['required', 'string', Rule::in(['approved', 'restricted', 'banned'])],
            'notes' => ['nullable', 'string'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        $ingredient = DB::transaction(function () use ($validated) {
            // Create ingredient
            $ingredient = Ingredient::create([
                'slug' => $validated['slug'],
                'cas_number' => $validated['cas_number'] ?? null,
                'is_allergen' => $validated['is_allergen'],
                'allergen_code' => $validated['allergen_code'] ?? null,
                'regulatory_status' => $validated['regulatory_status'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create translations
            foreach ($validated['translations'] as $translation) {
                IngredientTranslation::create([
                    'ingredient_id' => $ingredient->id,
                    'locale' => $translation['locale'],
                    'name' => $translation['name'],
                    'description' => $translation['description'] ?? null,
                ]);
            }

            return $ingredient;
        });

        return response()->json([
            'data' => IngredientData::fromModel($ingredient->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing ingredient.
     */
    #[CrossTenantRoute(reason: 'Ingredients catalog: updates a global ingredient record in the platform-shared catalog (no tenant_id on table); cross-tenant by data design.')]
    public function update(Request $request, string $id): JsonResponse
    {
        $ingredient = Ingredient::find($id);

        if (! $ingredient) {
            return response()->json([
                'error' => [
                    'code' => 'INGREDIENT_NOT_FOUND',
                    'message' => 'Ingredient not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('ingredients')->ignore($ingredient->id)],
            'cas_number' => ['nullable', 'string', 'max:50'],
            'is_allergen' => ['sometimes', 'boolean'],
            'allergen_code' => ['nullable', 'string', 'max:20'],
            'regulatory_status' => ['sometimes', 'string', Rule::in(['approved', 'restricted', 'banned'])],
            'notes' => ['nullable', 'string'],
            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.id' => ['nullable', 'string', 'exists:ingredient_translations,id'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($ingredient, $validated) {
            // Update ingredient core fields
            $coreFields = array_intersect_key($validated, array_flip([
                'slug',
                'cas_number',
                'is_allergen',
                'allergen_code',
                'regulatory_status',
                'notes',
            ]));

            if (! empty($coreFields)) {
                $ingredient->update($coreFields);
            }

            // Update translations if provided
            if (isset($validated['translations'])) {
                foreach ($validated['translations'] as $translation) {
                    if (isset($translation['id'])) {
                        // Update existing translation
                        IngredientTranslation::where('id', $translation['id'])
                            ->update([
                                'name' => $translation['name'],
                                'description' => $translation['description'] ?? null,
                            ]);
                    } else {
                        // Create new translation
                        IngredientTranslation::create([
                            'ingredient_id' => $ingredient->id,
                            'locale' => $translation['locale'],
                            'name' => $translation['name'],
                            'description' => $translation['description'] ?? null,
                        ]);
                    }
                }
            }
        });

        return response()->json([
            'data' => IngredientData::fromModel($ingredient->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete an ingredient.
     */
    #[CrossTenantRoute(reason: 'Ingredients catalog: deletes a global ingredient record (no tenant_id on table); destructive operation affects every tenant\'s reference catalog.')]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $ingredient = Ingredient::find($id);

        if (! $ingredient) {
            return response()->json([
                'error' => [
                    'code' => 'INGREDIENT_NOT_FOUND',
                    'message' => 'Ingredient not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Check if ingredient is in use
        $usageCount = DB::table('product_ingredient')
            ->where('ingredient_id', $id)
            ->count();

        if ($usageCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'INGREDIENT_IN_USE',
                    'message' => "Cannot delete ingredient. It is currently used in {$usageCount} product(s).",
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 409);
        }

        $ingredient->delete();

        return response()->json(null, 204);
    }
}
