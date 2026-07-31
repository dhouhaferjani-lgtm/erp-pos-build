<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Domain\Events\TerminalActivatedAudit;
use App\Modules\POS\Domain\Events\TerminalDeactivated;
use App\Modules\POS\Domain\Events\TerminalSoftwareUpdated;
use App\Modules\POS\Domain\Events\TerminalTrainingModeChanged;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Presentation\Requests\ClaimTerminalRequest;
use App\Modules\POS\Presentation\Requests\CreateTerminalRequest;
use App\Modules\POS\Presentation\Requests\RequestTerminalRequest;
use App\Modules\POS\Presentation\Requests\UpdateTerminalRequest;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        $terminals = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->with(['location', 'company'])
            ->withCount(['receipts', 'shifts'])
            // Phase 6.1: eager-load MAX(shift_number) so TerminalResource's
            // max_shift_number does not fire a lazy per-row query (N+1).
            ->withMax('shifts', 'shift_number')
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->with(['location', 'company'])
            // Offline-first shifts Phase 6.1: eager-load the per-terminal
            // MAX(shift_number) so the device can seed its counter without an
            // extra round-trip (TerminalResource falls back to a lazy MAX when
            // this attribute is absent).
            ->withMax('shifts', 'shift_number')
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
            // Provision-at-v3 (first-tenant launch, Lane D1): every terminal
            // created through this endpoint is fiscal schema 3 from creation.
            // Explicit here (not just relying on the column DEFAULT) so the
            // API contract is visible in code.
            'fiscal_schema_version' => 3,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        $previousVersion = $terminal->pos_software_version;

        $terminal->update($request->validated());

        // Detect software version change and dispatch audit event
        $newVersion = $terminal->pos_software_version;
        if ($previousVersion !== $newVersion && $newVersion !== null) {
            event(new TerminalSoftwareUpdated(
                terminalId: $terminal->id,
                terminalCode: $terminal->code,
                companyId: $terminal->company_id,
                previousVersion: $previousVersion ?? '',
                newVersion: $newVersion,
            ));
        }

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
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

        // Dispatch domain event for NF525 audit trail
        event(new TerminalActivatedAudit(
            terminalId: $terminal->id,
            terminalCode: $terminal->code,
            companyId: $terminal->company_id,
            activatedBy: (string) auth()->id(),
        ));

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        $reason = $request->input('reason', '');

        $terminal->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ]);

        // Dispatch domain event for NF525 audit trail
        event(new TerminalDeactivated(
            terminalId: $terminal->id,
            terminalCode: $terminal->code,
            companyId: $terminal->company_id,
            reason: (string) $reason,
            deactivatedBy: (string) auth()->id(),
        ));

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $terminals = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->physical()
            ->active()
            ->whereNull('hardware_identifier')
            ->with(['location', 'company'])
            // Phase 6.1: eager-load MAX(shift_number) to avoid a per-row N+1 in
            // TerminalResource (available terminals have no shifts, so this is
            // null → 0, but the eager attribute keeps the query count flat).
            ->withMax('shifts', 'shift_number')
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
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
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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
            // Provision-at-v3 (first-tenant launch, Lane D1): see store() above.
            'fiscal_schema_version' => 3,
            'is_active' => false,
            'hardware_identifier' => $data['hardware_identifier'],
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $company = $this->companyContext->requireCompany();

        // Resolve company BEFORE the validator runs so location_id can be
        // scoped via ScopedExists::company. locations has company_id only
        // (no tenant_id) — cross-tenant access is impossible because
        // companies.tenant_id pins membership; cross-company within same
        // tenant is the real attack vector and is closed here.
        // Inventory: api.pos-stabilization.019 (validator) + .028 (find).
        $request->validate([
            'location_id' => ['required', 'uuid', ScopedExists::company('locations', $company->id)],
        ]);

        $locationId = $request->input('location_id');

        // Look up existing active web terminal for (company, location)
        $terminal = Terminal::forCompany($company->id)
            ->forLocation($locationId)
            ->web()
            ->first();

        if ($terminal) {
            return response()->json([
                'data' => TerminalResource::make($terminal->load(['location', 'company'])),
            ]);
        }

        // Create a new web terminal — Location::findOrFail scoped to the
        // caller's company so a malicious validator-bypass cannot attach
        // a foreign location to the new terminal write. Inventory: .028.
        /** @var Location $location */
        $location = Location::query()
            ->where('company_id', $company->id)
            ->findOrFail($locationId);
        $locationCode = $location->code ?? 'MAIN';

        $terminal = Terminal::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $locationId,
            'type' => TerminalType::Web,
            'code' => 'WEB-'.strtoupper($locationCode),
            'name' => 'Web POS - '.$location->name,
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) now()->format('Y'),
            // Provision-at-v3 (first-tenant launch, Lane D1): see store() above.
            'fiscal_schema_version' => 3,
            'is_active' => true,
            'activated_at' => now(),
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
            'max_discount_percent' => '20.00',
        ]);

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
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

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->where('hardware_identifier', $hardwareIdentifier)
            ->with(['location', 'company'])
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
     * Toggle training mode on a terminal.
     *
     * Only allowed when the terminal has no open shift.
     *
     * POST /api/v1/pos/terminals/{id}/toggle-training
     */
    public function toggleTrainingMode(string $id): JsonResponse
    {
        Gate::authorize('pos.manage_terminals');

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        if ($terminal->shifts()->where('status', ShiftStatus::Open)->exists()) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_HAS_OPEN_SHIFT',
                    'message' => 'Cannot toggle training mode while a shift is open. Close the shift first.',
                ],
            ], 422);
        }

        $enabled = ! $terminal->is_training_mode;

        $terminal->update([
            'is_training_mode' => $enabled,
        ]);

        event(new TerminalTrainingModeChanged(
            terminalId: $terminal->id,
            terminalCode: $terminal->code,
            companyId: $terminal->company_id,
            enabled: $enabled,
            changedBy: (string) auth()->id(),
        ));

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
        ]);
    }

    /**
     * Get Z-chain state for a terminal (for recovery after local DB loss).
     *
     * Returns the latest Z-report hash, sequence, z_number, and grand totals
     * so the client can resume its Z-chain from server state.
     *
     * GET /api/v1/pos/terminals/{id}/z-chain-state
     */
    public function zChainState(string $id): JsonResponse
    {
        if (! Gate::any(['pos.manage_terminals', 'pos.operate_terminal'])) {
            abort(403);
        }

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        $latestZReport = ZReport::forTerminal($terminal->id)
            ->orderByDesc('z_number')
            ->first();

        if ($latestZReport === null) {
            return response()->json([
                'data' => [
                    'z_last_hash' => 'GENESIS',
                    'z_hash_sequence' => 0,
                    'z_number' => 0,
                    'grand_totals' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'z_last_hash' => $latestZReport->fiscal_hash,
                'z_hash_sequence' => ZReport::forTerminal($terminal->id)->count(),
                'z_number' => $latestZReport->z_number,
                'grand_totals' => $latestZReport->grand_totals,
            ],
        ]);
    }

    /**
     * Generate a unique terminal code
     */
    private function generateTerminalCode(): string
    {
        $companyId = $this->companyContext->requireCompanyId();
        $count = Terminal::withTrashed()->forCompany($companyId)->count();

        return 'POS'.str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT);
    }
}
