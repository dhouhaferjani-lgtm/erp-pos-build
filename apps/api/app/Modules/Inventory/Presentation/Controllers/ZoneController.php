<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\ZoneDto;
use App\Modules\Inventory\Application\DTOs\ZoneProductAssignmentDto;
use App\Modules\Inventory\Application\Services\ZoneService;
use App\Modules\Inventory\Domain\LocationZone;
use App\Modules\Inventory\Domain\ProductZoneAssignment;
use App\Modules\Inventory\Presentation\Requests\BulkAssignZoneRequest;
use App\Modules\Inventory\Presentation\Requests\CreateZoneRequest;
use App\Modules\Inventory\Presentation\Requests\UpdateZoneRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ZoneController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ZoneService $zoneService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $locationId = (string) $request->query('location_id', '');

        if (! Str::isUuid($locationId)) {
            return response()->json([
                'error' => [
                    'code' => 'ZONE_INVALID_LOCATION',
                    'message' => 'A valid location_id query parameter is required.',
                ],
            ], 422);
        }

        // 404s if the location doesn't belong to the current company.
        Location::query()->where('company_id', $company->id)->findOrFail($locationId);

        $zones = LocationZone::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('location_id', $locationId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $zones->map(fn (LocationZone $zone): ZoneDto => ZoneDto::fromModel($zone))->all(),
        ]);
    }

    public function store(CreateZoneRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $zone = $this->zoneService->createZone(
            tenantId: $company->tenant_id,
            locationId: (string) $request->input('location_id'),
            name: (string) $request->input('name'),
            code: (string) $request->input('code'),
            sortOrder: (int) $request->input('sort_order', 0),
            isActive: (bool) $request->input('is_active', true),
        );

        return response()->json(['data' => ZoneDto::fromModel($zone)], 201);
    }

    public function update(UpdateZoneRequest $request, string $zone): JsonResponse
    {
        $model = $this->resolveZoneForCompany($zone);

        /** @var array<string, mixed> $attributes */
        $attributes = array_intersect_key(
            $request->validated(),
            array_flip(['name', 'code', 'sort_order', 'is_active']),
        );

        $updated = $this->zoneService->updateZone($model, $attributes);

        return response()->json(['data' => ZoneDto::fromModel($updated)]);
    }

    public function destroy(string $zone): JsonResponse
    {
        $model = $this->resolveZoneForCompany($zone);

        $this->zoneService->deleteZone($model);

        return response()->json(null, 204);
    }

    public function assignProducts(BulkAssignZoneRequest $request, string $zone): JsonResponse
    {
        $model = $this->resolveZoneForCompany($zone);

        /** @var list<string> $productIds */
        $productIds = $request->input('product_ids', []);

        try {
            $this->zoneService->bulkAssignProducts($model->id, $productIds);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ZONE_LOCATION_MISMATCH',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $assignments = $this->zoneService->listZoneProducts($model->id);

        return response()->json([
            'data' => $assignments->map(fn (ProductZoneAssignment $a): ZoneProductAssignmentDto => ZoneProductAssignmentDto::fromModel($a))->all(),
        ]);
    }

    public function products(string $zone): JsonResponse
    {
        $model = $this->resolveZoneForCompany($zone);

        $assignments = $this->zoneService->listZoneProducts($model->id);

        return response()->json([
            'data' => $assignments->map(fn (ProductZoneAssignment $a): ZoneProductAssignmentDto => ZoneProductAssignmentDto::fromModel($a))->all(),
        ]);
    }

    private function resolveZoneForCompany(string $zoneId): LocationZone
    {
        if (! Str::isUuid($zoneId)) {
            abort(404);
        }

        $company = $this->companyContext->requireCompany();

        /** @var LocationZone $zone */
        $zone = LocationZone::query()
            ->where('tenant_id', $company->tenant_id)
            ->whereHas('location', function (Builder $query) use ($company): void {
                /** @var Builder<Location> $query */
                $query->where('company_id', $company->id);
            })
            ->findOrFail($zoneId);

        return $zone;
    }
}
