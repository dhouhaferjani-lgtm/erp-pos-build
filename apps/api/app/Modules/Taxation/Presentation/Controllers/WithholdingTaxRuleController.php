<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Application\DTOs\WithholdingRuleData;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Repositories\WithholdingTaxRuleRepositoryInterface;
use App\Modules\Taxation\Presentation\Requests\CreateWithholdingRuleRequest;
use App\Modules\Taxation\Presentation\Requests\UpdateWithholdingRuleRequest;
use App\Modules\Taxation\Presentation\Resources\WithholdingRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Withholding Tax Rule Controller
 *
 * Admin controller for managing withholding tax rules.
 */
class WithholdingTaxRuleController extends Controller
{
    public function __construct(
        private readonly WithholdingTaxRuleRepositoryInterface $ruleRepository,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List withholding tax rules.
     *
     * Shows global rules and company-specific rules.
     */
    public function index(Request $request): JsonResponse
    {
        $countryCode = $request->input('country_code');
        $companyId = $this->companyContext->getCompanyId();

        if ($countryCode) {
            $rules = $this->ruleRepository->findByCountry($countryCode);
        } elseif ($companyId) {
            $globalRules = $this->ruleRepository->findByCountry(
                $this->companyContext->requireCompany()->country_code
            );
            $companyRules = $this->ruleRepository->findByCompany($companyId);
            $rules = $globalRules->merge($companyRules)->sortBy('name');
        } else {
            // Fallback to Tunisia rules if no context
            $rules = $this->ruleRepository->findByCountry('TN');
        }

        return response()->json([
            'data' => WithholdingRuleResource::collection($rules),
        ]);
    }

    /**
     * Get a single rule.
     */
    public function show(string $id): JsonResponse
    {
        // api.taxation round-2 (Opus Finding 4, IMPORTANT): pre-fix
        // ruleRepository->findById was unscoped; tenant-A admin could read
        // tenant-B company-specific rules by UUID. Withholding tax rules
        // are dual-scoped: global rules (company_id IS NULL) are
        // intentionally cross-tenant readable; company-specific rules
        // (company_id IS NOT NULL) MUST be scoped to the current company.
        $rule = $this->loadReadableRule($id);

        return response()->json([
            'data' => WithholdingRuleResource::make($rule),
        ]);
    }

    /**
     * Create a new rule (admin only).
     */
    public function store(CreateWithholdingRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Add company_id if creating company-specific rule
        if ($request->input('is_company_specific')) {
            $data['company_id'] = $this->companyContext->requireCompanyId();
        }

        $rule = $this->ruleRepository->create($data);

        return response()->json([
            'data' => WithholdingRuleData::fromEntity($rule)->toArray(),
            'message' => 'Withholding tax rule created successfully',
        ], 201);
    }

    /**
     * Update a rule (admin only).
     */
    public function update(string $id, UpdateWithholdingRuleRequest $request): JsonResponse
    {
        // api.taxation round-2 (Opus Finding 4): writes are restricted to
        // company-specific rules belonging to current company. Global-rule
        // writes are admin-special-case and not exposed here.
        $this->requireCompanyScopedRule($id);

        $rule = $this->ruleRepository->update($id, $request->validated());

        return response()->json([
            'data' => WithholdingRuleData::fromEntity($rule)->toArray(),
            'message' => 'Withholding tax rule updated successfully',
        ]);
    }

    /**
     * Deactivate a rule (admin only).
     */
    public function deactivate(string $id): JsonResponse
    {
        // api.taxation round-2 (Opus Finding 4): same as update().
        $this->requireCompanyScopedRule($id);

        $this->ruleRepository->deactivate($id);

        return response()->json([
            'message' => 'Withholding tax rule deactivated successfully',
        ]);
    }

    /**
     * Delete a rule (admin only).
     */
    public function destroy(string $id): JsonResponse
    {
        // api.taxation round-2 (Opus Finding 4): same as update().
        $this->requireCompanyScopedRule($id);

        $this->ruleRepository->delete($id);

        return response()->json([
            'message' => 'Withholding tax rule deleted successfully',
        ]);
    }

    /**
     * Load a withholding rule readable by the current company.
     *
     * Returns a global rule (company_id IS NULL) OR a same-company rule.
     * 404s if the rule is company-specific to a foreign company.
     */
    private function loadReadableRule(string $id): WithholdingTaxRule
    {
        $companyId = $this->companyContext->getCompanyId();

        return WithholdingTaxRule::query()
            ->where('id', $id)
            ->where(function ($query) use ($companyId): void {
                $query->whereNull('company_id');
                if ($companyId !== null) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->firstOrFail();
    }

    /**
     * Pre-load a withholding rule writable by the current company.
     *
     * Requires the rule to be company-specific (company_id IS NOT NULL)
     * AND scoped to the current company. Global rules cannot be modified
     * via this endpoint — would be a separate admin-only flow.
     */
    private function requireCompanyScopedRule(string $id): WithholdingTaxRule
    {
        $companyId = $this->companyContext->requireCompanyId();

        return WithholdingTaxRule::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }
}
