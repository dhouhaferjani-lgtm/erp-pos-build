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
use App\Shared\Contracts\Treasury\LocationCashRegisterProvisionerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $scopeResolver,
        // Campaign lane N-12 — a POS-enabled location owns its own cash drawer.
        // Injected through the Shared contract so this module never touches a
        // Treasury model (rule 6); constructor injection only (rule 13).
        private readonly LocationCashRegisterProvisionerInterface $cashRegisterProvisioner,
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
     * List active locations that may be selected as transaction destinations.
     * Source/read visibility remains enforced by the scoped endpoints; a
     * destination may be outside the caller's read subset.
     */
    public function transactionIndex(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $ids = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $this->transactionPickerPayload($ids);
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
                'is_active' => $location->is_active,
            ])->values()->all(),
        ]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function transactionPickerPayload(Collection $locations): JsonResponse
    {
        return response()->json([
            'data' => $locations->map(static fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'type' => $location->type->value,
                'is_default' => $location->is_default,
                'is_active' => $location->is_active,
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

        $this->provisionCashRegisterIfPosEnabled($location);

        return response()->json([
            'data' => new LocationResource($location),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Campaign lane N-12 — give a POS-enabled location its own cash drawer.
     *
     * Without this, a second branch's POS cash had nowhere of its own to go and
     * the tender resolver silently booked it into whichever till sorted first,
     * i.e. Main's. The resolver now refuses that; this is what stops the refusal
     * from ever being reached in the ordinary flow.
     *
     * Deliberately best-effort and never fatal to the location write: a chart
     * that has no `cash` purpose account yet (the provisioner logs and returns
     * null) must not turn "create my branch" into a 500. The terminal-claim
     * refusal is the backstop that keeps money from moving in that state.
     */
    private function provisionCashRegisterIfPosEnabled(Location $location): void
    {
        if ($location->pos_enabled !== true) {
            return;
        }

        $this->cashRegisterProvisioner->provision(
            $this->companyContext->requireTenantId(),
            $this->companyContext->requireCompanyId(),
            $location->id,
            $location->code,
        );
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

        // N-12: enabling POS on an existing location is the same event as
        // creating one with POS on — the branch now takes cash and needs a
        // drawer of its own. Idempotent, so a no-op PATCH never mints a second.
        $this->provisionCashRegisterIfPosEnabled($freshLocation);

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
