<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\CertificationData;
use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\CertificationTranslation;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CertificationController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    /**
     * List all certifications with sorting and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            ['slug', 'type', 'is_active', 'display_order', 'created_at'],
            'display_order',
            'asc'
        );

        // Build query
        $query = Certification::query();

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Get per_page parameter
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Paginate
        $paginator = $query->paginate($perPage);

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, CertificationData::class)
        );
    }

    /**
     * Get a single certification by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $certification = Certification::find($id);

        if (! $certification) {
            return response()->json([
                'error' => [
                    'code' => 'CERTIFICATION_NOT_FOUND',
                    'message' => 'Certification not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => CertificationData::fromModel($certification),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new certification with translations.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:255', 'unique:certifications,slug'],
            'certifying_body' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:500', 'url'],
            'verification_url' => ['nullable', 'string', 'max:500', 'url'],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        $certification = DB::transaction(function () use ($validated) {
            // Create certification
            $certification = Certification::create([
                'type' => $validated['type'],
                'slug' => $validated['slug'],
                'certifying_body' => $validated['certifying_body'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'verification_url' => $validated['verification_url'] ?? null,
                'is_active' => $validated['is_active'],
                'display_order' => $validated['display_order'],
            ]);

            // Create translations
            foreach ($validated['translations'] as $translation) {
                CertificationTranslation::create([
                    'certification_id' => $certification->id,
                    'locale' => $translation['locale'],
                    'name' => $translation['name'],
                    'description' => $translation['description'] ?? null,
                ]);
            }

            return $certification;
        });

        /** @var \App\Modules\Product\Domain\Certification $freshCertification */
        $freshCertification = $certification->fresh();

        return response()->json([
            'data' => CertificationData::fromModel($freshCertification),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing certification.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $certification = Certification::find($id);

        if (! $certification) {
            return response()->json([
                'error' => [
                    'code' => 'CERTIFICATION_NOT_FOUND',
                    'message' => 'Certification not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('certifications')->ignore($certification->id)],
            'certifying_body' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:500', 'url'],
            'verification_url' => ['nullable', 'string', 'max:500', 'url'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.id' => ['nullable', 'string', 'exists:certification_translations,id'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($certification, $validated) {
            // Update certification core fields
            $coreFields = array_intersect_key($validated, array_flip([
                'type',
                'slug',
                'certifying_body',
                'logo_url',
                'verification_url',
                'is_active',
                'display_order',
            ]));

            if (! empty($coreFields)) {
                $certification->update($coreFields);
            }

            // Update translations if provided
            if (isset($validated['translations'])) {
                foreach ($validated['translations'] as $translation) {
                    if (isset($translation['id'])) {
                        // Update existing translation
                        CertificationTranslation::where('id', $translation['id'])
                            ->update([
                                'name' => $translation['name'],
                                'description' => $translation['description'] ?? null,
                            ]);
                    } else {
                        // Create new translation
                        CertificationTranslation::create([
                            'certification_id' => $certification->id,
                            'locale' => $translation['locale'],
                            'name' => $translation['name'],
                            'description' => $translation['description'] ?? null,
                        ]);
                    }
                }
            }
        });

        /** @var \App\Modules\Product\Domain\Certification $freshCertification */
        $freshCertification = $certification->fresh();

        return response()->json([
            'data' => CertificationData::fromModel($freshCertification),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a certification.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $certification = Certification::find($id);

        if (! $certification) {
            return response()->json([
                'error' => [
                    'code' => 'CERTIFICATION_NOT_FOUND',
                    'message' => 'Certification not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Check if certification is in use
        $usageCount = DB::table('certification_product')
            ->where('certification_id', $id)
            ->count();

        if ($usageCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'CERTIFICATION_IN_USE',
                    'message' => "Cannot delete certification. It is currently used in {$usageCount} product(s).",
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 409);
        }

        $certification->delete();

        return response()->json(null, 204);
    }
}
