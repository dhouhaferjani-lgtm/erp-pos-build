<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Controllers;

use App\Modules\Product\Application\DTOs\HealthClaimData;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\HealthClaimTranslation;
use App\Shared\Architecture\CrossTenantRoute;
use App\Support\Traits\FiltersAndSorts;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HealthClaimController extends Controller
{
    use FiltersAndSorts;
    use PaginatesResults;

    /**
     * List all health claims with sorting and filtering.
     */
    #[CrossTenantRoute(reason: 'Health claims catalog: global reference data (the health_claims table has no tenant_id column — every tenant queries the same shared regulatory health-claim catalog with EFSA/FDA references). Permission-gated by SetPermissionsTeam middleware on the route group, but cross-tenant by data design.')]
    public function index(Request $request): JsonResponse
    {
        // Get sort parameters
        $sortParams = $this->getSortParams(
            $request,
            ['slug', 'claim_type', 'regulatory_status', 'created_at'],
            'created_at',
            'desc'
        );

        // Build query
        $query = HealthClaim::query();

        // Apply sorting
        $this->applySorting($query, $sortParams);

        // Get per_page parameter
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Paginate
        $paginator = $query->paginate($perPage);

        return response()->json(
            $this->formatOffsetPaginatedResponse($paginator, HealthClaimData::class)
        );
    }

    /**
     * Get a single health claim by ID.
     */
    #[CrossTenantRoute(reason: 'Health claims catalog: reads a single global health-claim record from the platform-shared regulatory catalog (no tenant_id on table).')]
    public function show(Request $request, string $id): JsonResponse
    {
        $healthClaim = HealthClaim::find($id);

        if (! $healthClaim) {
            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CLAIM_NOT_FOUND',
                    'message' => 'Health claim not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => HealthClaimData::fromModel($healthClaim),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new health claim with translations.
     */
    #[CrossTenantRoute(reason: 'Health claims catalog: creates a new entry in the platform-shared regulatory health-claim catalog with locale translations; every tenant sees the new record. Permission-gated.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'claim_type' => ['required', 'string', Rule::in(['function', 'reduction_of_disease_risk', 'development_and_health'])],
            'slug' => ['required', 'string', 'max:255', 'unique:health_claims,slug'],
            'regulatory_status' => ['required', 'string', Rule::in(['approved', 'pending', 'rejected'])],
            'efsa_reference' => ['nullable', 'string', 'max:100'],
            'fda_reference' => ['nullable', 'string', 'max:100'],
            'country_restrictions' => ['nullable', 'array'],
            'requires_disclaimer' => ['required', 'boolean'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.claim' => ['required', 'string'],
            'translations.*.disclaimer_text' => ['nullable', 'string'],
        ]);

        $healthClaim = DB::transaction(function () use ($validated) {
            // Create health claim
            $healthClaim = HealthClaim::create([
                'claim_type' => $validated['claim_type'],
                'slug' => $validated['slug'],
                'regulatory_status' => $validated['regulatory_status'],
                'efsa_reference' => $validated['efsa_reference'] ?? null,
                'fda_reference' => $validated['fda_reference'] ?? null,
                'country_restrictions' => $validated['country_restrictions'] ?? null,
                'requires_disclaimer' => $validated['requires_disclaimer'],
            ]);

            // Create translations
            foreach ($validated['translations'] as $translation) {
                HealthClaimTranslation::create([
                    'health_claim_id' => $healthClaim->id,
                    'locale' => $translation['locale'],
                    'claim' => $translation['claim'],
                    'disclaimer_text' => $translation['disclaimer_text'] ?? null,
                ]);
            }

            return $healthClaim;
        });

        return response()->json([
            'data' => HealthClaimData::fromModel($healthClaim->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing health claim.
     */
    #[CrossTenantRoute(reason: 'Health claims catalog: updates a global health-claim record in the platform-shared regulatory catalog (no tenant_id on table); cross-tenant by data design.')]
    public function update(Request $request, string $id): JsonResponse
    {
        $healthClaim = HealthClaim::find($id);

        if (! $healthClaim) {
            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CLAIM_NOT_FOUND',
                    'message' => 'Health claim not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        $validated = $request->validate([
            'claim_type' => ['sometimes', 'string', Rule::in(['function', 'reduction_of_disease_risk', 'development_and_health'])],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('health_claims')->ignore($healthClaim->id)],
            'regulatory_status' => ['sometimes', 'string', Rule::in(['approved', 'pending', 'rejected'])],
            'efsa_reference' => ['nullable', 'string', 'max:100'],
            'fda_reference' => ['nullable', 'string', 'max:100'],
            'country_restrictions' => ['nullable', 'array'],
            'requires_disclaimer' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array', 'min:1'],
            'translations.*.id' => ['nullable', 'string', 'exists:health_claim_translations,id'],
            'translations.*.locale' => ['required', 'string', 'size:2'],
            'translations.*.claim' => ['required', 'string'],
            'translations.*.disclaimer_text' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($healthClaim, $validated) {
            // Update health claim core fields
            $coreFields = array_intersect_key($validated, array_flip([
                'claim_type',
                'slug',
                'regulatory_status',
                'efsa_reference',
                'fda_reference',
                'country_restrictions',
                'requires_disclaimer',
            ]));

            if (! empty($coreFields)) {
                $healthClaim->update($coreFields);
            }

            // Update translations if provided
            if (isset($validated['translations'])) {
                foreach ($validated['translations'] as $translation) {
                    if (isset($translation['id'])) {
                        // Update existing translation
                        HealthClaimTranslation::where('id', $translation['id'])
                            ->update([
                                'claim' => $translation['claim'],
                                'disclaimer_text' => $translation['disclaimer_text'] ?? null,
                            ]);
                    } else {
                        // Create new translation
                        HealthClaimTranslation::create([
                            'health_claim_id' => $healthClaim->id,
                            'locale' => $translation['locale'],
                            'claim' => $translation['claim'],
                            'disclaimer_text' => $translation['disclaimer_text'] ?? null,
                        ]);
                    }
                }
            }
        });

        return response()->json([
            'data' => HealthClaimData::fromModel($healthClaim->refresh()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a health claim.
     */
    #[CrossTenantRoute(reason: 'Health claims catalog: deletes a global health-claim record (no tenant_id on table) after checking health_claim_product usage; destructive operation affects every tenant\'s reference catalog.')]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $healthClaim = HealthClaim::find($id);

        if (! $healthClaim) {
            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CLAIM_NOT_FOUND',
                    'message' => 'Health claim not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Check if health claim is in use
        $usageCount = DB::table('health_claim_product')
            ->where('health_claim_id', $id)
            ->count();

        if ($usageCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'HEALTH_CLAIM_IN_USE',
                    'message' => "Cannot delete health claim. It is currently used in {$usageCount} product(s).",
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 409);
        }

        $healthClaim->delete();

        return response()->json(null, 204);
    }
}
