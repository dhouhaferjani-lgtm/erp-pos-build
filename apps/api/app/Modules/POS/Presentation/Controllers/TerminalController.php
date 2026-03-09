<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\ClaimTerminalRequest;
use App\Modules\POS\Presentation\Requests\CreateTerminalRequest;
use App\Modules\POS\Presentation\Requests\RequestTerminalRequest;
use App\Modules\POS\Presentation\Requests\UpdateTerminalRequest;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('pos.manage_terminals');

        $terminals = Terminal::forCompany($this->companyContext->getCompanyId())
            ->with(['location'])
            ->withCount(['receipts', 'shifts'])
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
        if (! Gate::any(['pos.manage_terminals', 'pos.operate_terminal'])) {
            abort(403);
        }

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
        Gate::authorize('pos.manage_terminals');

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
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
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
        Gate::authorize('pos.manage_terminals');

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        $terminal->update($request->validated());

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ]);
    }

    /**
     * Archive a terminal (soft delete).
     *
     * Data is preserved. Blocked if the terminal has an open shift.
     *
     * PATCH /api/v1/pos/terminals/{id}/archive
     */
    public function archive(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_terminals');

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        if ($terminal->shifts()->where('status', ShiftStatus::Open)->exists()) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_HAS_OPEN_SHIFT',
                    'message' => 'Cannot archive a terminal with an open shift. Close the shift first.',
                ],
            ], 422);
        }

        $terminal->delete(); // soft delete

        return response()->json(null, 204);
    }

    /**
     * Permanently delete a terminal (hard delete).
     *
     * Only allowed when the terminal has no receipts or shifts.
     *
     * DELETE /api/v1/pos/terminals/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_terminals');

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        if ($terminal->receipts()->exists() || $terminal->shifts()->exists()) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_HAS_HISTORY',
                    'message' => 'Cannot permanently delete a terminal with existing transactions or shifts. Use archive instead.',
                ],
            ], 422);
        }

        $terminal->forceDelete();

        return response()->json(null, 204);
    }

    /**
     * Activate a terminal
     *
     * PATCH /api/v1/pos/terminals/{id}/activate
     */
    public function activate(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_terminals');

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->findOrFail($id);

        $terminal->update([
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        TerminalActivated::dispatch(
            $terminal->id,
            $terminal->code,
            $terminal->name,
            $terminal->tenant_id,
            $terminal->company_id,
        );

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
        Gate::authorize('pos.manage_terminals');

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
     * List available (unclaimed) physical terminals.
     *
     * GET /api/v1/pos/terminals/available
     */
    public function available(): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminals = Terminal::forCompany($this->companyContext->getCompanyId())
            ->physical()
            ->active()
            ->whereNull('hardware_identifier')
            ->with(['location'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => TerminalResource::collection($terminals),
        ]);
    }

    /**
     * Claim an existing physical terminal for this device.
     *
     * POST /api/v1/pos/terminals/claim
     */
    public function claim(ClaimTerminalRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $data = $request->validated();

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->where('id', $data['terminal_id'])
            ->firstOrFail();

        if ($terminal->type !== TerminalType::Physical) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_PHYSICAL_TERMINAL',
                    'message' => 'Only physical terminals can be claimed',
                ],
            ], 422);
        }

        if (! $terminal->is_active) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_INACTIVE',
                    'message' => 'Terminal is not active',
                ],
            ], 422);
        }

        if ($terminal->hardware_identifier !== null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_ALREADY_CLAIMED',
                    'message' => 'Terminal is already claimed by another device',
                ],
            ], 409);
        }

        $terminal->update([
            'hardware_identifier' => $data['hardware_identifier'],
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ]);
    }

    /**
     * Request a new physical terminal (created inactive, pending admin activation).
     *
     * POST /api/v1/pos/terminals/request
     */
    public function requestTerminal(RequestTerminalRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $data = $request->validated();
        $company = $this->companyContext->requireCompany();

        $terminal = Terminal::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $data['location_id'],
            'type' => TerminalType::Physical,
            'code' => $this->generateTerminalCode(),
            'name' => $data['suggested_name'],
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
            'is_active' => false,
            'hardware_identifier' => $data['hardware_identifier'],
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ], 201);
    }

    /**
     * Get or create the web terminal for a given location.
     *
     * POST /api/v1/pos/terminals/web
     */
    public function getOrCreateWebTerminal(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

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
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load('location')),
        ], 201);
    }

    /**
     * Find a terminal assigned to a specific device (by hardware identifier).
     *
     * GET /api/v1/pos/terminals/by-device/{hardwareIdentifier}
     */
    public function findByDevice(string $hardwareIdentifier): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminal = Terminal::forCompany($this->companyContext->getCompanyId())
            ->where('hardware_identifier', $hardwareIdentifier)
            ->with(['location'])
            ->first();

        if (! $terminal) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => TerminalResource::make($terminal),
        ]);
    }

    /**
     * Generate a unique terminal code
     */
    private function generateTerminalCode(): string
    {
        $companyId = $this->companyContext->getCompanyId();
        $count = Terminal::withTrashed()->forCompany($companyId)->count();

        return 'POS'.str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);
    }
}
