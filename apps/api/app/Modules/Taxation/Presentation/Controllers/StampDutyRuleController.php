<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use App\Modules\Taxation\Presentation\Resources\StampDutyRuleResource;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class StampDutyRuleController extends Controller
{
    /**
     * List all stamp duty rules
     */
    #[CrossTenantRoute(reason: 'Stamp-duty rules catalog: global reference data scoped by country_code (no tenant_id column on stamp_duty_rules table — every tenant queries the same shared regulatory tax catalog filtered by country/document_type/active status). Cross-tenant by data design.')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StampDutyRule::query();

        // Filter by country if provided
        if ($request->has('country_code')) {
            $query->where('country_code', $request->input('country_code'));
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by document type
        if ($request->has('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }

        $rules = $query->orderBy('country_code')
            ->orderBy('document_type')
            ->orderBy('effective_from', 'desc')
            ->get();

        return StampDutyRuleResource::collection($rules);
    }

    /**
     * Get a single stamp duty rule
     */
    #[CrossTenantRoute(reason: 'Stamp-duty rules catalog: reads a single global rule from the platform-shared regulatory catalog (no tenant_id on table); rules are country-scoped, not tenant-scoped.')]
    public function show(string $id): StampDutyRuleResource
    {
        $rule = StampDutyRule::findOrFail($id);

        return new StampDutyRuleResource($rule);
    }

    /**
     * Create a new stamp duty rule
     */
    #[CrossTenantRoute(reason: 'Stamp-duty rules catalog: creates a new rule in the platform-shared regulatory catalog (country_code-scoped, no tenant_id); every tenant in that country sees the new rule.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'document_type' => ['required', 'string', 'max:50'],
            'fiscal_category' => ['nullable', 'string', 'max:50'],
            'stamp_amount' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'metadata' => ['nullable', 'array'],
        ]);

        $rule = StampDutyRule::create($validated);

        return response()->json([
            'data' => new StampDutyRuleResource($rule),
        ], 201);
    }

    /**
     * Update a stamp duty rule
     */
    #[CrossTenantRoute(reason: 'Stamp-duty rules catalog: updates a global rule in the platform-shared regulatory catalog; cross-tenant by data design (country_code-scoped).')]
    public function update(Request $request, string $id): StampDutyRuleResource
    {
        $rule = StampDutyRule::findOrFail($id);

        $validated = $request->validate([
            'stamp_amount' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'metadata' => ['nullable', 'array'],
        ]);

        $rule->update($validated);

        return new StampDutyRuleResource($rule);
    }

    /**
     * Delete a stamp duty rule
     */
    #[CrossTenantRoute(reason: 'Stamp-duty rules catalog: deletes a global rule from the platform-shared regulatory catalog; destructive operation affects every tenant in that country.')]
    public function destroy(string $id): JsonResponse
    {
        $rule = StampDutyRule::findOrFail($id);
        $rule->delete();

        return response()->json(null, 204);
    }
}
