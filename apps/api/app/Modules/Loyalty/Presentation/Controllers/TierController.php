<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\TierData;
use App\Modules\Loyalty\Domain\Entities\Tier;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\TierRepositoryInterface;
use App\Modules\Loyalty\Presentation\Requests\CreateTierRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateTierRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller for tier management
 */
class TierController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TierRepositoryInterface $tierRepository,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    /**
     * List all tiers for a specific program
     */
    public function index(string $programId): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $tiers = $this->tierRepository->findByProgram($programId);

        return response()->json([
            'data' => $tiers->map(fn (Tier $tier) => TierData::fromModel($tier))->toArray(),
        ]);
    }

    /**
     * Get a single tier
     */
    public function show(string $id): JsonResponse
    {
        $tier = $this->tierRepository->findById($id);

        if ($tier === null) {
            return response()->json([
                'error' => [
                    'code' => 'TIER_NOT_FOUND',
                    'message' => 'Tier not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($tier->program_id);

        return response()->json([
            'data' => TierData::fromModel($tier),
        ]);
    }

    /**
     * Create a new tier
     */
    public function store(string $programId, CreateTierRequest $request): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $data = array_merge($request->validated(), [
            'program_id' => $programId,
        ]);

        $tier = new Tier($data);
        $tier = $this->tierRepository->save($tier);

        return response()->json([
            'data' => TierData::fromModel($tier),
            'message' => 'Tier created successfully',
        ], 201);
    }

    /**
     * Update an existing tier
     */
    public function update(string $id, UpdateTierRequest $request): JsonResponse
    {
        $tier = $this->tierRepository->findById($id);

        if ($tier === null) {
            return response()->json([
                'error' => [
                    'code' => 'TIER_NOT_FOUND',
                    'message' => 'Tier not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($tier->program_id);

        $tier->fill($request->validated());
        $tier = $this->tierRepository->save($tier);

        return response()->json([
            'data' => TierData::fromModel($tier),
            'message' => 'Tier updated successfully',
        ]);
    }

    /**
     * Delete a tier
     */
    public function destroy(string $id): JsonResponse
    {
        $tier = $this->tierRepository->findById($id);

        if ($tier === null) {
            return response()->json([
                'error' => [
                    'code' => 'TIER_NOT_FOUND',
                    'message' => 'Tier not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($tier->program_id);

        $this->tierRepository->delete($id);

        return response()->json([
            'message' => 'Tier deleted successfully',
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
