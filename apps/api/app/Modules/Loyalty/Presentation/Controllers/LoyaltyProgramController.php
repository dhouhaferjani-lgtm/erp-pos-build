<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Application\DTOs\LoyaltyProgramData;
use App\Modules\Loyalty\Application\Services\ProgramManagementService;
use App\Modules\Loyalty\Presentation\Requests\CreateProgramRequest;
use App\Modules\Loyalty\Presentation\Requests\UpdateProgramRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller for loyalty program management (admin operations)
 */
class LoyaltyProgramController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ProgramManagementService $programService,
    ) {}

    /**
     * List all loyalty programs for the current tenant
     */
    public function index(): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $programs = $this->programService->getProgramsByTenant($tenantId);

        return response()->json([
            'data' => $programs->toArray(),
        ]);
    }

    /**
     * Get a single loyalty program
     */
    public function show(string $id): JsonResponse
    {
        $program = $this->programService->getProgram($id);

        return response()->json([
            'data' => $program,
        ]);
    }

    /**
     * Create a new loyalty program
     */
    public function store(CreateProgramRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $data = array_merge($request->validated(), [
            'tenant_id' => $tenantId,
        ]);

        $program = $this->programService->createProgram($data);

        return response()->json([
            'data' => $program,
            'message' => 'Loyalty program created successfully',
        ], 201);
    }

    /**
     * Update an existing loyalty program
     */
    public function update(UpdateProgramRequest $request, string $id): JsonResponse
    {
        $program = $this->programService->updateProgram($id, $request->validated());

        return response()->json([
            'data' => $program,
            'message' => 'Loyalty program updated successfully',
        ]);
    }

    /**
     * Delete a loyalty program (only inactive programs can be deleted)
     */
    public function destroy(string $id): JsonResponse
    {
        $this->programService->deleteProgram($id);

        return response()->json([
            'message' => 'Loyalty program deleted successfully',
        ]);
    }

    /**
     * Activate a loyalty program
     */
    public function activate(string $id): JsonResponse
    {
        $program = $this->programService->activateProgram($id);

        return response()->json([
            'data' => $program,
            'message' => 'Loyalty program activated successfully',
        ]);
    }

    /**
     * Deactivate a loyalty program
     */
    public function deactivate(string $id): JsonResponse
    {
        $program = $this->programService->deactivateProgram($id);

        return response()->json([
            'data' => $program,
            'message' => 'Loyalty program deactivated successfully',
        ]);
    }

    /**
     * Get active loyalty programs for the current tenant
     */
    public function active(): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $programs = $this->programService->getActivePrograms($tenantId);

        return response()->json([
            'data' => $programs->toArray(),
        ]);
    }
}
