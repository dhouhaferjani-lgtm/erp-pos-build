<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\KeyComponentData;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\KeyComponentTranslation;
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
