<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Controllers;

use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Presentation\Requests\CreateLocationRequest;
use App\Modules\Company\Presentation\Requests\UpdateLocationRequest;
use App\Modules\Company\Presentation\Resources\LocationResource;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $scopeResolver,
    ) {}

    /**
     * List locations visible to the authenticated user as a picker payload.
     */
    public function scopedIndex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->pickerPayload($this->scopeResolver->resolve($user));
    }

    /**
     * List every location in the bound company for location-access management.
     */
    public function managementIndex(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $ids = Location::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        return $this->pickerPayload($ids);
    }

    /**
     * List all locations for the current company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        /** @var User $user */
        $user = $request->user();

        $locations = Location::where('company_id', $companyId)
            ->whereIn('id', $this->scopeResolver->resolve($user))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => LocationResource::collection($locations),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function pickerPayload(array $ids): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locations = Location::query()
            ->whereIn('id', $ids)
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $locations->map(static fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'type' => $location->type->value,
                'is_default' => $location->is_default,
            ])->values()->all(),
        ]);
    }

    /**
     * Get a single location.
     */
    public function show(Request $request, string $location): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locationModel = Location::where('company_id', $companyId)
            ->where('id', $location)
            ->first();

        if (! $locationModel) {
            return response()->json([
                'error' => [
                    'code' => 'LOCATION_NOT_FOUND',
                    'message' => 'Location not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        return response()->json([
            'data' => new LocationResource($locationModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a new location.
     */
    public function store(CreateLocationRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Auto-generate code if not provided
        $code = $validated['code'] ?? null;
        if (empty($code)) {
            $count = Location::where('company_id', $companyId)->count();
            $code = sprintf('LOC-%03d', $count + 1);
        }

        $location = Location::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'code' => $code,
            'type' => LocationType::from($validated['type']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_street' => $validated['address_street'] ?? null,
            'address_city' => $validated['address_city'] ?? null,
            'address_postal_code' => $validated['address_postal_code'] ?? null,
            'address_country' => $validated['address_country'] ?? null,
            'tax_id' => $validated['tax_id'] ?? null,
            'vat_number' => $validated['vat_number'] ?? null,
            'legal_identifiers' => $validated['legal_identifiers'] ?? null,
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => $validated['pos_enabled'] ?? false,
            'onboarding_mode' => $validated['onboarding_mode'] ?? false,
            'pos_stock_policy_override' => $validated['pos_stock_policy_override'] ?? null,
        ]);

        return response()->json([
            'data' => new LocationResource($location),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update an existing location.
     */
    public function update(UpdateLocationRequest $request, string $location): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locationModel = Location::where('company_id', $companyId)
            ->where('id', $location)
            ->first();

        if (! $locationModel) {
            return response()->json([
                'error' => [
                    'code' => 'LOCATION_NOT_FOUND',
                    'message' => 'Location not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Convert type string to enum if present
        if (isset($validated['type'])) {
            $validated['type'] = LocationType::from($validated['type']);
        }

        $locationModel->update($validated);

        /** @var Location $freshLocation */
        $freshLocation = $locationModel->fresh();

        return response()->json([
            'data' => new LocationResource($freshLocation),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Delete a location (soft delete).
     */
    public function destroy(Request $request, string $location): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locationModel = Location::where('company_id', $companyId)
            ->where('id', $location)
            ->first();

        if (! $locationModel) {
            return response()->json([
                'error' => [
                    'code' => 'LOCATION_NOT_FOUND',
                    'message' => 'Location not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        // Cannot delete default location
        if ($locationModel->is_default) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_DELETE_DEFAULT_LOCATION',
                    'message' => 'Cannot delete the default location. Set another location as default first.',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        $locationModel->delete();

        return response()->json(null, 204);
    }

    /**
     * Set a location as the default.
     */
    public function setDefault(Request $request, string $location): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $locationModel = Location::where('company_id', $companyId)
            ->where('id', $location)
            ->first();

        if (! $locationModel) {
            return response()->json([
                'error' => [
                    'code' => 'LOCATION_NOT_FOUND',
                    'message' => 'Location not found',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 404);
        }

        DB::transaction(function () use ($companyId, $locationModel): void {
            // Remove default from all other locations
            Location::where('company_id', $companyId)
                ->where('id', '!=', $locationModel->id)
                ->update(['is_default' => false]);

            // Set this location as default
            $locationModel->update(['is_default' => true]);
        });

        /** @var Location $freshLocation */
        $freshLocation = $locationModel->fresh();

        return response()->json([
            'data' => new LocationResource($freshLocation),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
