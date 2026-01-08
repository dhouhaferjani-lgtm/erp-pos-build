<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Application\DTOs\WithholdingRuleData;
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
        $rule = $this->ruleRepository->findById($id);

        if (! $rule) {
            return response()->json([
                'error' => [
                    'code' => 'RULE_NOT_FOUND',
                    'message' => 'Withholding tax rule not found',
                ],
            ], 404);
        }

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
        $this->ruleRepository->delete($id);

        return response()->json([
            'message' => 'Withholding tax rule deleted successfully',
        ]);
    }
}
