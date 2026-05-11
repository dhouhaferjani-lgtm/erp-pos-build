<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\KeyComponentData;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\KeyComponentTranslation;
use App\Shared\Architecture\CrossTenantRoute;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KeyComponentController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    /**
     * List all key components with sorting and filtering.
     */
    #[CrossTenantRoute(reason: 'Key components catalog: global reference data (the product_key_components table has no tenant_id column — every tenant queries the same shared regulatory key-component catalog). Permission-gated by SetPermissionsTeam middleware on the route group, but cross-tenant by data design.')]
    public function index(Request $request): JsonResponse
    {
        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            ['slug', 'is_allergen', 'created_at'],
            'created_at',
            'desc'
        );

        // Build query
        $query = KeyComponent::query();

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Get per_page parameter
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Paginate
        $paginator = $query->paginate($perPage);

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, KeyComponentData::class)
        );
    }

    /**
     * Get a single key component by ID.
     */
    #[CrossTenantRoute(reason: 'Key components catalog: reads a single global key-component record from the platform-shared catalog (no tenant_id on table).')]
    public function show(Request $request, string $id): JsonResponse
    {
        $keyComponent = KeyComponent::find($id);

        if (! $keyComponent) {
            return response()->json([
                'error' => [
                    'code' => 'KEY_COMPONENT_NOT_FOUND',
                    'message' => 'Key component not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => KeyComponentData::fromModel($keyComponent),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new key component with translations.
     */
    #[CrossTenantRoute(reason: 'Key components catalog: creates a new entry in the platform-shared key-component catalog; every tenant sees the new record. Permission-gated.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255', 'unique:product_key_components,slug'],
            'is_allergen' => ['required', 'boolean'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        $keyComponent = DB::transaction(function () use ($validated) {
            // Create key component
            $keyComponent = KeyComponent::create([
                'slug' => $validated['slug'],
                'is_allergen' => $validated['is_allergen'],
            ]);

            // Create translations
            foreach ($validated['translations'] as $translation) {
                KeyComponentTranslation::create([
                    'component_id' => $keyComponent->id,
                    'locale' => $translation['locale'],
                    'name' => $translation['name'],
                    'description' => $translation['description'] ?? null,
                ]);
            }

            return $keyComponent;
        });

        return response()->json([
            'data' => KeyComponentData::fromModel($keyComponent->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing key component.
     */
    #[CrossTenantRoute(reason: 'Key components catalog: updates a global key-component record in the platform-shared catalog (no tenant_id on table); cross-tenant by data design.')]
    public function update(Request $request, string $id): JsonResponse
    {
        $keyComponent = KeyComponent::find($id);

        if (! $keyComponent) {
            return response()->json([
                'error' => [
                    'code' => 'KEY_COMPONENT_NOT_FOUND',
                    'message' => 'Key component not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('product_key_components')->ignore($keyComponent->id)],
            'is_allergen' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.id' => ['nullable', 'string', 'exists:key_component_translations,id'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($keyComponent, $validated) {
            // Update key component core fields
            $coreFields = array_intersect_key($validated, array_flip([
                'slug',
                'is_allergen',
            ]));

            if (! empty($coreFields)) {
                $keyComponent->update($coreFields);
            }

            // Update translations if provided
            if (isset($validated['translations'])) {
                foreach ($validated['translations'] as $translation) {
                    if (isset($translation['id'])) {
                        // Update existing translation
                        KeyComponentTranslation::where('id', $translation['id'])
                            ->update([
                                'name' => $translation['name'],
                                'description' => $translation['description'] ?? null,
                            ]);
                    } else {
                        // Create new translation
                        KeyComponentTranslation::create([
                            'component_id' => $keyComponent->id,
                            'locale' => $translation['locale'],
                            'name' => $translation['name'],
                            'description' => $translation['description'] ?? null,
                        ]);
                    }
                }
            }
        });

        return response()->json([
            'data' => KeyComponentData::fromModel($keyComponent->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a key component.
     */
    #[CrossTenantRoute(reason: 'Key components catalog: deletes a global key-component record (no tenant_id on table) after checking key_component_product usage; destructive operation affects every tenant\'s reference catalog.')]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $keyComponent = KeyComponent::find($id);

        if (! $keyComponent) {
            return response()->json([
                'error' => [
                    'code' => 'KEY_COMPONENT_NOT_FOUND',
                    'message' => 'Key component not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Check if key component is in use
        $usageCount = DB::table('key_component_product')
            ->where('component_id', $id)
            ->count();

        if ($usageCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'KEY_COMPONENT_IN_USE',
                    'message' => "Cannot delete key component. It is currently used in {$usageCount} product(s).",
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 409);
        }

        $keyComponent->delete();

        return response()->json(null, 204);
    }
}
