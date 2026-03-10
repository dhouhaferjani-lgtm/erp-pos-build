<?php

declare(strict_types=1);

namespace App\Modules\Uom\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Uom\Application\DTOs\ConversionResultData;
use App\Modules\Uom\Application\DTOs\UnitCategoryData;
use App\Modules\Uom\Application\DTOs\UnitData;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Services\UnitConversionService;
use App\Modules\Uom\Presentation\Requests\CreateUnitRequest;
use App\Modules\Uom\Presentation\Requests\UpdateUnitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class UomController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly UnitConversionService $conversionService,
    ) {}

    /**
     * List all categories with their units
     */
    public function indexCategories(Request $request): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $categories = UnitCategory::query()
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->where('is_active', true)
            ->with(['units' => function ($query) {
                $query->where('is_active', true);
            }])
            ->orderBy('name')
            ->get();

        $data = $categories->map(fn ($cat) => UnitCategoryData::fromModel($cat));

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * List all units (filterable by category)
     */
    public function indexUnits(Request $request): JsonResponse
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $categoryId = $request->query('category_id');

        $query = Unit::query()
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->where('is_active', true)
            ->with('category');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $units = $query->orderBy('name')->get();
        $data = $units->map(fn ($unit) => UnitData::fromModel($unit));

        return response()->json([
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single unit
     */
    public function showUnit(Request $request, string $id): JsonResponse
    {
        /** @var Unit $unit */
        $unit = Unit::with('category')->findOrFail($id);

        return response()->json([
            'data' => UnitData::fromModel($unit),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Create a custom unit (tenant admin only)
     */
    public function storeUnit(CreateUnitRequest $request): JsonResponse
    {
        Gate::authorize('uom.create');

        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $unit = Unit::create([
            'tenant_id' => $tenantId,
            'category_id' => $validated['category_id'],
            'code' => $validated['code'],
            'name' => $validated['name'],
            'symbol' => $validated['symbol'],
            'conversion_factor' => $validated['conversion_factor'],
            'decimal_places' => $validated['decimal_places'] ?? 2,
            'rounding_method' => $validated['rounding_method'] ?? 'half_up',
            'is_base_unit' => false,
            'is_system' => false,
            'is_active' => true,
        ]);

        $unit->load('category');

        return response()->json([
            'data' => UnitData::fromModel($unit),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 201);
    }

    /**
     * Update a custom unit (cannot update system units)
     */
    public function updateUnit(UpdateUnitRequest $request, string $id): JsonResponse
    {
        Gate::authorize('uom.edit');

        /** @var Unit $unit */
        $unit = Unit::findOrFail($id);

        if ($unit->is_system) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_UNIT',
                    'message' => 'System units cannot be modified',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $unit->update($validated);
        $unit->load('category');

        return response()->json([
            'data' => UnitData::fromModel($unit),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Deactivate a unit (soft delete via is_active flag)
     */
    public function destroyUnit(Request $request, string $id): JsonResponse
    {
        Gate::authorize('uom.delete');

        /** @var Unit $unit */
        $unit = Unit::findOrFail($id);

        if ($unit->is_system) {
            return response()->json([
                'error' => [
                    'code' => 'SYSTEM_UNIT',
                    'message' => 'System units cannot be deleted',
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        // Check if unit is in use
        $productsCount = \App\Modules\Product\Domain\Product::where('unit_id', $id)->count();
        if ($productsCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'UNIT_IN_USE',
                    'message' => "Unit is used by {$productsCount} product(s). Deactivating instead of deleting.",
                ],
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
                ],
            ], 422);
        }

        $unit->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    /**
     * Convert quantity between units
     */
    public function convert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0',
            'from_unit_id' => 'required|uuid|exists:units,id',
            'to_unit_id' => 'required|uuid|exists:units,id',
        ]);

        /** @var Unit $fromUnit */
        $fromUnit = Unit::with('category')->findOrFail($validated['from_unit_id']);
        /** @var Unit $toUnit */
        $toUnit = Unit::with('category')->findOrFail($validated['to_unit_id']);

        $convertedQty = $this->conversionService->convert(
            (string) $validated['quantity'],
            $fromUnit,
            $toUnit
        );

        $factor = $this->conversionService->getConversionFactor($fromUnit, $toUnit);

        $result = new ConversionResultData(
            originalQuantity: (string) $validated['quantity'],
            originalUnit: UnitData::fromModel($fromUnit),
            convertedQuantity: $convertedQty,
            convertedUnit: UnitData::fromModel($toUnit),
            conversionFactor: $factor,
        );

        return response()->json([
            'data' => $result,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
