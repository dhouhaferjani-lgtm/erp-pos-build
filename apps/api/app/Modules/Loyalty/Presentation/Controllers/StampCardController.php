<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\StampCardData;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use App\Modules\Loyalty\Domain\Repositories\LoyaltyProgramRepositoryInterface;
use App\Modules\Loyalty\Domain\Repositories\StampCardRepositoryInterface;
use App\Modules\Loyalty\Presentation\Requests\CreateStampCardRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateStampCardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller for stamp card definition management
 */
class StampCardController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly StampCardRepositoryInterface $stampCardRepository,
        private readonly LoyaltyProgramRepositoryInterface $programRepository,
    ) {}

    /**
     * List all stamp card definitions for a specific program
     */
    public function index(string $programId): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $stampCards = $this->stampCardRepository->findDefinitionsByProgram($programId);

        return response()->json([
            'data' => $stampCards->map(fn (StampCardDefinition $card) => StampCardData::fromModel($card))->toArray(),
        ]);
    }

    /**
     * Get a single stamp card definition
     */
    public function show(string $id): JsonResponse
    {
        $stampCard = $this->stampCardRepository->findDefinitionById($id);

        if ($stampCard === null) {
            return response()->json([
                'error' => [
                    'code' => 'STAMP_CARD_NOT_FOUND',
                    'message' => 'Stamp card definition not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($stampCard->program_id);

        return response()->json([
            'data' => StampCardData::fromModel($stampCard),
        ]);
    }

    /**
     * Create a new stamp card definition
     */
    public function store(string $programId, CreateStampCardRequest $request): JsonResponse
    {
        $this->validateProgramAccess($programId);

        $data = array_merge($request->validated(), [
            'program_id' => $programId,
        ]);

        $stampCard = new StampCardDefinition($data);
        $stampCard = $this->stampCardRepository->saveDefinition($stampCard);

        return response()->json([
            'data' => StampCardData::fromModel($stampCard),
            'message' => 'Stamp card definition created successfully',
        ], 201);
    }

    /**
     * Update an existing stamp card definition
     */
    public function update(string $id, UpdateStampCardRequest $request): JsonResponse
    {
        $stampCard = $this->stampCardRepository->findDefinitionById($id);

        if ($stampCard === null) {
            return response()->json([
                'error' => [
                    'code' => 'STAMP_CARD_NOT_FOUND',
                    'message' => 'Stamp card definition not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($stampCard->program_id);

        $stampCard->fill($request->validated());
        $stampCard = $this->stampCardRepository->saveDefinition($stampCard);

        return response()->json([
            'data' => StampCardData::fromModel($stampCard),
            'message' => 'Stamp card definition updated successfully',
        ]);
    }

    /**
     * Delete a stamp card definition
     */
    public function destroy(string $id): JsonResponse
    {
        $stampCard = $this->stampCardRepository->findDefinitionById($id);

        if ($stampCard === null) {
            return response()->json([
                'error' => [
                    'code' => 'STAMP_CARD_NOT_FOUND',
                    'message' => 'Stamp card definition not found',
                ],
            ], 404);
        }

        $this->validateProgramAccess($stampCard->program_id);

        $this->stampCardRepository->deleteDefinition($id);

        return response()->json([
            'message' => 'Stamp card definition deleted successfully',
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
