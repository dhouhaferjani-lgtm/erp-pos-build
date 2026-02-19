<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\RewardData;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\RewardRepositoryInterface;
use App\Modules\Loyalty\Presentation\Requests\CreateRewardRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateRewardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller for reward management
 */
class RewardController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RewardRepositoryInterface $rewardRepository,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    /**
     * List all rewards for a specific program
     */
    public function index(string $programId): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $rewards = $this->rewardRepository->findByProgram($programId);

        return response()->json([
            'data' => $rewards->map(fn (Reward $reward) => RewardData::fromModel($reward))->toArray(),
        ]);
    }

    /**
     * Get a single reward
     */
    public function show(string $id): JsonResponse
    {
        $reward = $this->rewardRepository->findById($id);

        if ($reward === null) {
            return response()->json([
                'error' => [
                    'code' => 'REWARD_NOT_FOUND',
                    'message' => 'Reward not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($reward->program_id);

        return response()->json([
            'data' => RewardData::fromModel($reward),
        ]);
    }

    /**
     * Create a new reward
     */
    public function store(string $programId, CreateRewardRequest $request): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $data = array_merge($request->validated(), [
            'program_id' => $programId,
        ]);

        $reward = new Reward($data);
        $reward = $this->rewardRepository->save($reward);

        return response()->json([
            'data' => RewardData::fromModel($reward),
            'message' => 'Reward created successfully',
        ], 201);
    }

    /**
     * Update an existing reward
     */
    public function update(string $id, UpdateRewardRequest $request): JsonResponse
    {
        $reward = $this->rewardRepository->findById($id);

        if ($reward === null) {
            return response()->json([
                'error' => [
                    'code' => 'REWARD_NOT_FOUND',
                    'message' => 'Reward not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($reward->program_id);

        $reward->fill($request->validated());
        $reward = $this->rewardRepository->save($reward);

        return response()->json([
            'data' => RewardData::fromModel($reward),
            'message' => 'Reward updated successfully',
        ]);
    }

    /**
     * Delete a reward
     */
    public function destroy(string $id): JsonResponse
    {
        $reward = $this->rewardRepository->findById($id);

        if ($reward === null) {
            return response()->json([
                'error' => [
                    'code' => 'REWARD_NOT_FOUND',
                    'message' => 'Reward not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($reward->program_id);

        $this->rewardRepository->delete($id);

        return response()->json([
            'message' => 'Reward deleted successfully',
        ]);
    }

    /**
     * Activate a reward
     */
    public function activate(string $id): JsonResponse
    {
        $reward = $this->rewardRepository->findById($id);

        if ($reward === null) {
            return response()->json([
                'error' => [
                    'code' => 'REWARD_NOT_FOUND',
                    'message' => 'Reward not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($reward->program_id);

        $reward->is_active = true;
        $reward = $this->rewardRepository->save($reward);

        return response()->json([
            'data' => RewardData::fromModel($reward),
            'message' => 'Reward activated successfully',
        ]);
    }

    /**
     * Deactivate a reward
     */
    public function deactivate(string $id): JsonResponse
    {
        $reward = $this->rewardRepository->findById($id);

        if ($reward === null) {
            return response()->json([
                'error' => [
                    'code' => 'REWARD_NOT_FOUND',
                    'message' => 'Reward not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($reward->program_id);

        $reward->is_active = false;
        $reward = $this->rewardRepository->save($reward);

        return response()->json([
            'data' => RewardData::fromModel($reward),
            'message' => 'Reward deactivated successfully',
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
