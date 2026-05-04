<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\ModifierGroupData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Presentation\Requests\StoreModifierGroupRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class ModifierGroupController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $query = ModifierGroup::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with('modifiers');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $groups = $query->orderBy('display_order')->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $groups->map(fn (ModifierGroup $g) => ModifierGroupData::fromModel($g)),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $group = ModifierGroup::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with('modifiers')
            ->findOrFail($id);

        return response()->json(['data' => ModifierGroupData::fromModel($group)]);
    }

    public function store(StoreModifierGroupRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $group = ModifierGroup::create([
            ...$request->validated(),
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => ModifierGroupData::fromModel($group)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $group = ModifierGroup::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'selection_type' => ['sometimes', 'string'],
            'min_selections' => ['sometimes', 'integer', 'min:0'],
            'max_selections' => ['sometimes', 'integer', 'min:1'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $group->update($validated);
        $group->load('modifiers');

        return response()->json(['data' => ModifierGroupData::fromModel($group)]);
    }

    public function destroy(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $group = ModifierGroup::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
        $group->delete();

        return response()->json(null, 204);
    }

    /**
     * Assign a modifier group to a composite item.
     */
    public function assignToItem(Request $request, string $compositeItemId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $validated = $request->validate([
            'modifier_group_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('modifier_groups', $company->tenant_id, $company->id),
            ],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $item = CompositeItem::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);

        $item->modifierGroups()->syncWithoutDetaching([
            $validated['modifier_group_id'] => [
                'display_order' => $validated['display_order'] ?? 0,
            ],
        ]);

        $item->load('modifierGroups.modifiers');

        return response()->json([
            'data' => $item->modifierGroups->map(fn (ModifierGroup $g) => ModifierGroupData::fromModel($g)),
        ]);
    }

    /**
     * Remove a modifier group from a composite item.
     */
    public function removeFromItem(string $compositeItemId, string $modifierGroupId): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($compositeItemId) || ! Str::isUuid($modifierGroupId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $item = CompositeItem::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($compositeItemId);
        $item->modifierGroups()->detach($modifierGroupId);

        return response()->json(null, 204);
    }
}
