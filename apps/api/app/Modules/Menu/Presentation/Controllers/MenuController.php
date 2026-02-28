<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Controllers;

use App\Modules\Menu\Application\DTOs\MenuData;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Presentation\Requests\StoreMenuRequest;
use App\Modules\Menu\Presentation\Requests\UpdateMenuRequest;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $query = Menu::query()
            ->forCompany($companyId)
            ->with(['categories.items']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $menus = $query->orderBy('is_default', 'desc')
            ->orderBy('display_order')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $menus->map(fn (Menu $menu) => MenuData::fromModel($menu)),
            'meta' => [
                'current_page' => $menus->currentPage(),
                'last_page' => $menus->lastPage(),
                'per_page' => $menus->perPage(),
                'total' => $menus->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $menu = Menu::query()
            ->forCompany($companyId)
            ->with(['categories.items'])
            ->findOrFail($id);

        return response()->json(['data' => MenuData::fromModel($menu)]);
    }

    public function store(StoreMenuRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();

        // Enforce single default menu per company
        if (! empty($validated['is_default'])) {
            Menu::query()
                ->forCompany($company->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $menu = Menu::create([
            ...$validated,
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);

        $menu->load(['categories.items']);

        return response()->json(['data' => MenuData::fromModel($menu)], 201);
    }

    public function update(UpdateMenuRequest $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $menu = Menu::query()->forCompany($companyId)->findOrFail($id);
        $validated = $request->validated();

        // Enforce single default menu per company
        if (! empty($validated['is_default'])) {
            Menu::query()
                ->forCompany($companyId)
                ->where('id', '!=', $menu->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $menu->update($validated);
        $menu->load(['categories.items']);

        return response()->json(['data' => MenuData::fromModel($menu)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $menu = Menu::query()->forCompany($companyId)->findOrFail($id);

        if ($menu->is_default) {
            return response()->json(['message' => 'Cannot delete the default menu'], 422);
        }

        $menu->delete();

        return response()->json(null, 204);
    }
}
