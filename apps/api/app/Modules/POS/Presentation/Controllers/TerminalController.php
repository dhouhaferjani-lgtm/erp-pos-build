<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\CreateTerminalRequest;
use App\Modules\POS\Presentation\Requests\UpdateTerminalRequest;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Controller for POS Terminal management.
 *
 * Handles:
 * - Listing terminals
 * - Creating new terminals
 * - Updating terminal details
 * - Activating/deactivating terminals
 * - Deleting terminals
 */
final class TerminalController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all terminals for the current company
     *
     * GET /api/v1/pos/terminals
     */
    public function index(Request $request): JsonResponse
    {
        $terminals = Terminal::forCompany($this->companyContext->getCompanyId())
            ->with(['location'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => TerminalResource::collection($terminals),
        ]);
    }

    /**
     * Get a single terminal
     *
     * GET /api/v1/pos/terminals/{id}
     */
    public function show(string $id): JsonResponse
    {
        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->with(['location'])
            ->findOrFail($id);

        return response()->json([
            'data' => TerminalResource::make($terminal),
        ]);
    }

    /**
     * Create a new terminal
     *
     * POST /api/v1/pos/terminals
     */
    public function store(CreateTerminalRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Auto-generate code if not provided
        if (empty($data['code'])) {
            $data['code'] = $this->generateTerminalCode();
        }

        $company = $this->companyContext->requireCompany();

        $terminal = Terminal::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $data['location_id'],
            'type' => TerminalType::Physical,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'genesis_seed' => Str::random(64), // Auto-generate 256-bit seed
            'current_sequence' => 0,
            'current_year' => (int) now()->format('Y'),
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ], 201);
    }

    /**
     * Update an existing terminal
     *
     * PATCH /api/v1/pos/terminals/{id}
     */
    public function update(string $id, UpdateTerminalRequest $request): JsonResponse
    {
        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        $terminal->update($request->validated());

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ]);
    }

    /**
     * Delete a terminal
     *
     * DELETE /api/v1/pos/terminals/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        // Check if terminal has any receipts/shifts
        if ($terminal->receipts()->exists()) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_HAS_RECEIPTS',
                    'message' => 'Cannot delete terminal with existing receipts',
                ],
            ], 422);
        }

        $terminal->delete();

        return response()->json(null, 204);
    }

    /**
     * Activate a terminal
     *
     * PATCH /api/v1/pos/terminals/{id}/activate
     */
    public function activate(string $id): JsonResponse
    {
        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        $terminal->update([
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ]);
    }

    /**
     * Deactivate a terminal
     *
     * PATCH /api/v1/pos/terminals/{id}/deactivate
     */
    public function deactivate(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        $terminal->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $request->input('reason'),
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ]);
    }

    /**
     * Get or create the web terminal for a given location.
     *
     * POST /api/v1/pos/terminals/web
     */
    public function getOrCreateWebTerminal(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
        ]);

        $company = $this->companyContext->requireCompany();
        $locationId = $request->input('location_id');

        // Look up existing active web terminal for (company, location)
        $terminal = Terminal::forCompany($company->id)
            ->forLocation($locationId)
            ->web()
            ->first();

        if ($terminal) {
            return response()->json([
                'data' => TerminalResource::make($terminal->load('location')),
            ]);
        }

        // Create a new web terminal
        /** @var Location $location */
        $location = Location::findOrFail($locationId);
        $locationCode = $location->code ?? 'MAIN';

        $terminal = Terminal::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $locationId,
            'type' => TerminalType::Web,
            'code' => 'WEB-' . strtoupper($locationCode),
            'name' => 'Web POS - ' . $location->name,
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 0,
            'current_year' => (int) now()->format('Y'),
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ], 201);
    }

    /**
     * Generate a unique terminal code
     */
    private function generateTerminalCode(): string
    {
        $companyId = $this->companyContext->getCompanyId();
        $count = Terminal::forCompany($companyId)->count();

        return 'POS'.str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);
    }
}
