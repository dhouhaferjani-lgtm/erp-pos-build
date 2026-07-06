<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\EarningRuleData;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Repositories\EarningRuleRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Presentation\Requests\CreateEarningRuleRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateEarningRuleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller for earning rule management
 */
class EarningRuleController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly EarningRuleRepositoryInterface $earningRuleRepository,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    /**
     * List all earning rules for a specific program
     */
    public function index(string $programId): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $rules = $this->earningRuleRepository->findByProgram($programId);

        return response()->json([
            'data' => $rules->map(fn (EarningRule $rule) => EarningRuleData::fromModel($rule))->toArray(),
        ]);
    }

    /**
     * Get a single earning rule
     */
    public function show(string $id): JsonResponse
    {
        $rule = $this->earningRuleRepository->findById($id);

        if ($rule === null) {
            return response()->json([
                'error' => [
                    'code' => 'EARNING_RULE_NOT_FOUND',
                    'message' => 'Earning rule not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($rule->program_id);

        return response()->json([
            'data' => EarningRuleData::fromModel($rule),
        ]);
    }

    /**
     * Create a new earning rule
     */
    public function store(string $programId, CreateEarningRuleRequest $request): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $data = array_merge($request->validated(), [
            'program_id' => $programId,
        ]);
        // validated() drops an empty conditions array (nested-rule rebuild);
        // the column is NOT NULL, so an unconditional rule must persist [].
        $data['conditions'] ??= [];

        $rule = new EarningRule($data);
        $rule = $this->earningRuleRepository->save($rule);

        return response()->json([
            'data' => EarningRuleData::fromModel($rule),
            'message' => 'Earning rule created successfully',
        ], 201);
    }

    /**
     * Update an existing earning rule
     */
    public function update(string $id, UpdateEarningRuleRequest $request): JsonResponse
    {
        $rule = $this->earningRuleRepository->findById($id);

        if ($rule === null) {
            return response()->json([
                'error' => [
                    'code' => 'EARNING_RULE_NOT_FOUND',
                    'message' => 'Earning rule not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($rule->program_id);

        $rule->fill($request->validated());
        $rule = $this->earningRuleRepository->save($rule);

        return response()->json([
            'data' => EarningRuleData::fromModel($rule),
            'message' => 'Earning rule updated successfully',
        ]);
    }

    /**
     * Delete an earning rule
     */
    public function destroy(string $id): JsonResponse
    {
        $rule = $this->earningRuleRepository->findById($id);

        if ($rule === null) {
            return response()->json([
                'error' => [
                    'code' => 'EARNING_RULE_NOT_FOUND',
                    'message' => 'Earning rule not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($rule->program_id);

        $this->earningRuleRepository->delete($id);

        return response()->json([
            'message' => 'Earning rule deleted successfully',
        ]);
    }

    /**
     * Activate an earning rule
     */
    public function activate(string $id): JsonResponse
    {
        $rule = $this->earningRuleRepository->findById($id);

        if ($rule === null) {
            return response()->json([
                'error' => [
                    'code' => 'EARNING_RULE_NOT_FOUND',
                    'message' => 'Earning rule not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($rule->program_id);

        $rule->is_active = true;
        $rule = $this->earningRuleRepository->save($rule);

        return response()->json([
            'data' => EarningRuleData::fromModel($rule),
            'message' => 'Earning rule activated successfully',
        ]);
    }

    /**
     * Deactivate an earning rule
     */
    public function deactivate(string $id): JsonResponse
    {
        $rule = $this->earningRuleRepository->findById($id);

        if ($rule === null) {
            return response()->json([
                'error' => [
                    'code' => 'EARNING_RULE_NOT_FOUND',
                    'message' => 'Earning rule not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($rule->program_id);

        $rule->is_active = false;
        $rule = $this->earningRuleRepository->save($rule);

        return response()->json([
            'data' => EarningRuleData::fromModel($rule),
            'message' => 'Earning rule deactivated successfully',
        ]);
    }

    /**
     * Validate that the program belongs to the current tenant
     */
    private function validateProgramAccess(string $programId): void
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $program = $this->programRepository->findById($programId);

        if ($program === null || $program->tenant_id !== $tenantId) {
            abort(403, 'You do not have access to this program');
        }
    }
}
