<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Vehicle\Application\DTOs\VehicleData;
use App\Modules\Vehicle\Application\DTOs\VehicleWithCurrentOwnerData;
use App\Modules\Vehicle\Application\Services\VehicleOwnershipService;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Presentation\Requests\CreateVehicleRequest;
use App\Modules\Vehicle\Presentation\Requests\UpdateVehicleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VehicleController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly VehicleOwnershipService $ownershipService,
    ) {}

    /**
     * List all vehicles for the current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = Vehicle::forTenant($tenantId);

        // Filter by partner
        $partnerId = $request->query('partner_id');
        if (is_string($partnerId) && $partnerId !== '') {
            $query->where('partner_id', $partnerId);
        }

        // Search by license plate, VIN, or brand
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('license_plate', 'like', "%{$search}%")
                    ->orWhere('vin', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $vehicles = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'data' => $vehicles->map(fn (Vehicle $vehicle): VehicleData => VehicleData::fromModel($vehicle)),
            'meta' => [
                'current_page' => $vehicles->currentPage(),
                'per_page' => $vehicles->perPage(),
                'total' => $vehicles->total(),
            ],
        ]);
    }

    /**
     * Get a single vehicle with current owner + recent mileage readings eager-loaded.
     */
    public function show(Request $request, string $vehicle): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $vehicleModel = Vehicle::forTenant($tenantId)
            ->with('currentOwnership.ownerPartner')
            ->find($vehicle);

        if ($vehicleModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Vehicle not found',
                ],
            ], 404);
        }

        return response()->json([
            'data' => VehicleWithCurrentOwnerData::fromModel($vehicleModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a new vehicle
     */
    public function store(CreateVehicleRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $vehicleModel = Vehicle::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
        ]);

        // Open the initial ownership history row when partner_id is supplied,
        // so the customer→vehicle lineage is recorded from the start of the
        // chain (closes 🔴-1 from 2026-04-21 autospecs-ops-audit).
        $partnerId = isset($validated['partner_id']) && is_string($validated['partner_id'])
            ? $validated['partner_id']
            : null;
        if ($partnerId !== null && $partnerId !== '') {
            /** @var User $actor */
            $actor = $request->user();
            $this->ownershipService->openInitialOwnership(
                vehicle: $vehicleModel,
                ownerPartnerId: $partnerId,
                acquiredAt: new \DateTimeImmutable,
                recordedByUserId: $actor->id,
            );
        }

        /** @var Vehicle $freshVehicle */
        $freshVehicle = $vehicleModel->fresh();

        return response()->json([
            'data' => VehicleData::fromModel($freshVehicle),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Update an existing vehicle
     */
    public function update(UpdateVehicleRequest $request, string $vehicle): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $vehicleModel = Vehicle::forTenant($tenantId)->find($vehicle);

        if ($vehicleModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Vehicle not found',
                ],
            ], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $vehicleModel->update($validated);

        /** @var Vehicle $freshVehicle */
        $freshVehicle = $vehicleModel->fresh();

        return response()->json([
            'data' => VehicleData::fromModel($freshVehicle),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Delete a vehicle (soft delete)
     */
    public function destroy(Request $request, string $vehicle): Response|JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $vehicleModel = Vehicle::forTenant($tenantId)->find($vehicle);

        if ($vehicleModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Vehicle not found',
                ],
            ], 404);
        }

        $vehicleModel->delete();

        return response()->noContent();
    }
}
