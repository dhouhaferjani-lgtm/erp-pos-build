<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\ModifierData;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Presentation\Requests\StoreModifierRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class ModifierController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function store(StoreModifierRequest $request, string $groupId): JsonResponse
    {
        if (! Str::isUuid($groupId)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();
        $group = ModifierGroup::where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($groupId);

        $modifier = Modifier::create([
            ...$request->validated(),
            'modifier_group_id' => $group->id,
        ]);

        return response()->json(['data' => ModifierData::fromModel($modifier)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        // The modifiers table has no tenant_id / company_id columns; scoping
        // is enforced via the parent modifier_group's tenant + company.
        $modifier = Modifier::whereHas('group', function (Builder $q) use ($company): void {
            $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                ->whereRaw('company_id = ?', [$company->id]);
        })
            ->with('group')
            ->findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'price_adjustment' => ['sometimes', 'numeric'],
            'component_type' => ['nullable', 'string'],
            'component_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'component_quantity' => ['nullable', 'numeric', 'min:0'],
            'component_unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $modifier->update($validated);

        return response()->json(['data' => ModifierData::fromModel($modifier)]);
    }

    public function destroy(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Invalid ID format'], 400);
        }

        $company = $this->companyContext->requireCompany();

        $modifier = Modifier::whereHas('group', function (Builder $q) use ($company): void {
            $q->whereRaw('tenant_id = ?', [$company->tenant_id])
                ->whereRaw('company_id = ?', [$company->id]);
        })
            ->with('group')
            ->findOrFail($id);

        $modifier->delete();

        return response()->json(null, 204);
    }
}
