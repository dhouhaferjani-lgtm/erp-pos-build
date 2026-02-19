<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Application\DTOs\ModifierData;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Presentation\Requests\StoreModifierRequest;
use App\Modules\Company\Services\CompanyContext;
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

        $companyId = $this->companyContext->requireCompanyId();
        $group = ModifierGroup::where('company_id', $companyId)->findOrFail($groupId);

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

        $modifier = Modifier::with('group')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($modifier->group->company_id !== $companyId) {
            abort(403);
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:255'],
            'price_adjustment' => ['sometimes', 'numeric'],
            'component_type' => ['nullable', 'string'],
            'component_id' => ['nullable', 'uuid', 'exists:products,id'],
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

        $modifier = Modifier::with('group')->findOrFail($id);

        $companyId = $this->companyContext->requireCompanyId();
        if ($modifier->group->company_id !== $companyId) {
            abort(403);
        }

        $modifier->delete();

        return response()->json(null, 204);
    }
}
