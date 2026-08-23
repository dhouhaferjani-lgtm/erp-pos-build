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
use App\Modules\POS\Domain\Events\TerminalClaimed;
use App\Modules\POS\Domain\Events\TerminalDeactivated;
use App\Modules\POS\Domain\Events\TerminalReleased;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // B-3: same refusal on the admin creation path as on the device paths.
        if (! $this->locationHasPosEnabled((string) $data['location_id'], $company->id)) {
            return $this->posDisabledResponse();
        }

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
            // B-3 (owner ruling 2026-08-23): never OFFER what claim() would
            // refuse. Without this the picker lists terminals the device is
            // then told it cannot have, which reads as a bug to the operator.
            ->whereHas('location', function (Builder $query): void {
                /** @var Builder<Location> $query */
                $query->where('pos_enabled', true);
            })
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

        // B-3: the location's POS switch is authoritative for acquisition.
        // Deliberately AFTER the is_active check — an inactive terminal is the
        // nearer, more actionable cause, so it keeps reporting first.
        if (! $this->locationHasPosEnabled($terminal->location_id, $terminal->company_id)) {
            return $this->posDisabledResponse();
        }

        if ($terminal->hardware_identifier !== null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_ALREADY_CLAIMED',
                    'message' => 'Terminal is already claimed by another device',
                ],
            ], 409);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $hardwareIdentifier = (string) $data['hardware_identifier'];

        // Q-7: courtesy pre-check so the ordinary "this box already runs another
        // till" mistake answers with a named 409 instead of surfacing as a
        // caught constraint violation. It is NOT the guarantee — it can be raced
        // exactly like the null-check above. The guarantee is
        // `pos_terminals_unique_hardware_identifier`, caught below.
        if ($this->hardwareBoundElsewhere($companyId, $hardwareIdentifier, $terminal->id)) {
            return $this->deviceAlreadyBoundResponse();
        }

        // Q-7: THE CLAIM IS SETTLED HERE, by a conditional UPDATE — not by the
        // in-memory check above. Between `firstOrFail()` and this line the
        // request runs the `pos_enabled` lookup, and a competing device can bind
        // the terminal inside that window; the previous plain `WHERE id = ?`
        // update overwrote the winner and answered 200 to BOTH devices, which
        // put two physical tills on one NF525 chain. `WHERE hardware_identifier
        // IS NULL` makes the database the arbiter: the loser matches zero rows.
        //
        // The `DB::transaction()` wrapper is NOT for atomicity — a single
        // UPDATE is already atomic. It is for CONTAINMENT on PostgreSQL: a
        // caught `QueryException` leaves the enclosing transaction aborted
        // (SQLSTATE 25P02) and every later statement in the request then fails,
        // so the 409 below would never be reached whenever this endpoint runs
        // inside a transaction. `DB::transaction()` opens a SAVEPOINT when one
        // is already active and rolls back to that alone, which is what makes
        // the catch an honest guarantee rather than a hope. Same trap the B-3
        // backfill migration documents.
        try {
            $claimed = DB::transaction(fn (): int => Terminal::forCompany($companyId)
                ->whereKey($terminal->id)
                ->whereNull('hardware_identifier')
                ->update(['hardware_identifier' => $hardwareIdentifier]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->deviceAlreadyBoundResponse();
            }

            throw $e;
        }

        if ($claimed === 0) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_ALREADY_CLAIMED',
                    'message' => 'Terminal is already claimed by another device',
                ],
            ], 409);
        }

        $terminal->refresh();

        // Binding a device to a terminal binds it to that terminal's fiscal
        // chain. `activate()`/`deactivate()` have always written an audit event
        // for exactly this reason; claiming wrote nothing at all.
        event(new TerminalClaimed(
            terminalId: $terminal->id,
            terminalCode: $terminal->code,
            companyId: $terminal->company_id,
            hardwareIdentifier: $hardwareIdentifier,
            claimedBy: (string) auth()->id(),
        ));

        return response()->json([
            'data' => TerminalResource::make($terminal->load(['location', 'company'])),
        ]);
    }

    /**
     * Release a terminal from the device currently bound to it.
     *
     * POST /api/v1/pos/terminals/{id}/release
     *
     * Before Q-7 there was NO way back: `deactivate()` leaves
     * `hardware_identifier` set, and nothing else ever cleared it, so a till
     * whose hardware died could not be re-homed onto its replacement — the
     * terminal row, and therefore its fiscal chain, was stranded.
     *
     * DELIBERATELY NOT BLOCKED BY AN OPEN SHIFT, unlike `archive()` and
     * `toggleTrainingMode()`. The reason to release is usually that the device
     * is gone (lost, stolen, bricked), and a gone device's shift can no longer
     * be closed from the device — an open-shift guard would make precisely the
     * terminals that need re-homing the ones that can never be re-homed, which
     * is the state this endpoint exists to remove. Closing out the orphaned
     * shift is the shift surface's job, not this one.
     */
    public function release(string $id, Request $request): JsonResponse
    {
        Gate::authorize('pos.manage_terminals');

        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $terminal = Terminal::forCompany($this->companyContext->requireCompanyId())
            ->findOrFail($id);

        $previousHardwareIdentifier = $terminal->hardware_identifier;

        // Idempotent: releasing an unclaimed terminal is a no-op that answers
        // 200 (a retried request must not fail) and writes NO audit event — an
        // audit register that records releases which never happened is worse
        // than one that records none.
        if ($previousHardwareIdentifier === null) {
            return response()->json([
                'data' => TerminalResource::make($terminal->load(['location', 'company'])),
            ]);
        }

        $reason = (string) $request->input('reason', '');

        $terminal->update([
            'hardware_identifier' => null,
        ]);

        event(new TerminalReleased(
            terminalId: $terminal->id,
            terminalCode: $terminal->code,
            companyId: $terminal->company_id,
            hardwareIdentifier: $previousHardwareIdentifier,
            reason: $reason,
            releasedBy: (string) auth()->id(),
        ));

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

        // B-3: refuse BEFORE the write — a terminal must never exist at a
        // location whose POS is switched off, otherwise the switch only stops
        // claiming and the device provisions itself a fresh terminal instead.
        if (! $this->locationHasPosEnabled((string) $data['location_id'], $company->id)) {
            return $this->posDisabledResponse();
        }

        $hardwareIdentifier = (string) $data['hardware_identifier'];

        // Q-7: this path was the UNCHECKED writer of `hardware_identifier` —
        // `Terminal::create()` with no collision test of any kind, so one device
        // could provision itself N terminals and `findByDevice()` would then
        // hand it back an arbitrary one of them. The pre-check names the cause;
        // the caught 23505 below is what makes it true under concurrency.
        if ($this->hardwareBoundElsewhere($company->id, $hardwareIdentifier, null)) {
            return $this->deviceAlreadyBoundResponse();
        }

        // SAVEPOINT-contained for the same reason as `claim()` above: on
        // PostgreSQL a caught QueryException poisons the enclosing transaction.
        try {
            $terminal = DB::transaction(fn (): Terminal => Terminal::create([
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
                'hardware_identifier' => $hardwareIdentifier,
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->deviceAlreadyBoundResponse();
            }

            throw $e;
        }

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

        $locationId = (string) $request->input('location_id');

        // B-3: refuse BEFORE the get-or-create lookup, so switching POS off at
        // a location also stops it handing back the web terminal it already
        // provisioned. Refusing only the CREATE half would leave every
        // previously-provisioned location selling regardless of the switch.
        if (! $this->locationHasPosEnabled($locationId, $company->id)) {
            return $this->posDisabledResponse();
        }

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
            // NOT v3 (first-tenant launch, Lane D1 round-2 fiscal-pos review
            // fix): web terminals are server-authoritative by construction —
            // there is no device to author SESSION_OPEN/SESSION_CLOSE locally,
            // so a v3 web terminal would hit ShiftController's device-authority
            // guard (ShiftController.php:60-70,124-134 — `fiscal_schema_version
            // >= 3` => 409 SHIFT_DEVICE_AUTHORITY_REQUIRED) and permanently
            // dead-end the web shifts dashboard for the first new tenant. The
            // `pos_terminals.fiscal_schema_version` column DEFAULT is now 3
            // (migration 2026_07_31_000001), so this explicit 2 is
            // LOAD-BEARING — omitting it would silently flip web terminals to
            // v3 via the column default.
            'fiscal_schema_version' => 2,
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
            // Q-7: DETERMINISTIC. `pos_terminals_unique_hardware_identifier`
            // makes duplicates impossible going forward, but it is skipped for
            // any brownfield tenant that already had some (the migration reports
            // and skips rather than aborting the tenant's whole run), and for
            // those tenants an unordered `first()` could hand the device a
            // DIFFERENT terminal — a different fiscal chain — between two calls.
            // Oldest binding wins: it is the one that already has receipts.
            // `id` breaks a `created_at` tie so the order is total, not merely
            // usually-defined.
            ->orderBy('created_at')
            ->orderBy('id')
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
     * B-3 (owner ruling 2026-08-23) — is POS switched on at this location?
     *
     * FAILS CLOSED. A `location_id` that does not resolve inside the caller's
     * company returns false rather than throwing, so every acquisition path
     * answers with the same 422 instead of leaking a 404/500 difference
     * between "disabled" and "not yours". The company scope is re-applied here
     * and not taken on trust from the caller: `claim()` reaches this with a
     * `location_id` copied off the terminal row, the other three with one that
     * came from the request body.
     */
    private function locationHasPosEnabled(string $locationId, string $companyId): bool
    {
        return Location::query()
            ->where('company_id', $companyId)
            ->whereKey($locationId)
            ->where('pos_enabled', true)
            ->exists();
    }

    /**
     * Q-7 — is this hardware identifier already bound to a LIVE terminal in
     * this company?
     *
     * Company-scoped, mirroring `pos_terminals_unique_hardware_identifier`'s own
     * scope: the same physical identifier in another company of the same tenant
     * is legal and already pinned by
     * `TerminalDeviceLookupTest::test_find_by_device_scoped_to_company`. The
     * SoftDeletes global scope excludes archived rows, matching the index's
     * `deleted_at IS NULL` predicate — so re-registering the hardware of a
     * terminal that was archived is allowed, which is the whole point of
     * archiving rather than purging.
     *
     * @param  string|null  $exceptTerminalId  The terminal being claimed, so a
     *                                         re-claim by the device that
     *                                         already holds it is not reported
     *                                         as a collision with itself.
     */
    private function hardwareBoundElsewhere(string $companyId, string $hardwareIdentifier, ?string $exceptTerminalId): bool
    {
        $query = Terminal::forCompany($companyId)
            ->where('hardware_identifier', $hardwareIdentifier);

        if ($exceptTerminalId !== null) {
            $query->whereKeyNot($exceptTerminalId);
        }

        return $query->exists();
    }

    /**
     * Q-7 — was this a UNIQUE violation ON `hardware_identifier` specifically?
     *
     * PostgreSQL reports SQLSTATE `23505`; SQLite reports the generic `23000`
     * and names the failure in the driver message, so the SQLite branch matches
     * on that text rather than on `23000` — which also covers NOT NULL and
     * foreign-key failures and must NOT be swallowed as a 409.
     *
     * BOTH halves are then narrowed to the column. `pos_terminals` carries
     * OTHER unique constraints — `pos_terminals_unique_code` above all, whose
     * collision is genuinely reachable because `generateTerminalCode()` derives
     * the next code from a `count()` (a TOCTOU the 2026-08-23 sweep records
     * separately, and which this lane does not fix). Reporting that as
     * `DEVICE_ALREADY_BOUND` would tell the operator to release a terminal that
     * has nothing to do with the failure. PostgreSQL names the violated index in
     * the message (`pos_terminals_unique_hardware_identifier`) and SQLite names
     * the columns (`pos_terminals.hardware_identifier`), so the same substring
     * discriminates on both drivers. Anything else rethrows.
     *
     * The DRIVER's message is used, not `QueryException::getMessage()`: Laravel
     * appends the failing SQL to the latter, and an INSERT's column list names
     * `hardware_identifier` whatever the violated constraint was — matching on
     * it would classify every unique violation on this table as a binding
     * collision, which is the exact mis-attribution this method exists to avoid.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $driverMessage = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        $isUnique = (string) $e->getCode() === '23505'
            || str_contains($driverMessage, 'UNIQUE constraint failed');

        return $isUnique && str_contains($driverMessage, 'hardware_identifier');
    }

    /**
     * Deliberately a DISTINCT code from `TERMINAL_ALREADY_CLAIMED`, and used by
     * both `claim()` and `requestTerminal()`.
     *
     * The two 409s answer different questions and need different remedies:
     * `TERMINAL_ALREADY_CLAIMED` means "that till belongs to another device —
     * pick a different one", while this one means "THIS device already runs a
     * till — release that one first, or use the till you already have". The
     * lane brief asked for a distinct code on the `requestTerminal()` leg; the
     * condition is identical on the `claim()` leg, so it carries the same code
     * rather than two names for one fact. The POS client rethrows the envelope
     * and surfaces `error.message` (`terminalStore.ts:745-759`), so no device
     * build depends on the code string.
     */
    private function deviceAlreadyBoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'DEVICE_ALREADY_BOUND',
                'message' => 'This device is already bound to another terminal. Release that terminal before claiming a new one.',
            ],
        ], 409);
    }

    /**
     * The single refusal envelope for every B-3 acquisition path, shaped like
     * the sibling refusals in this controller (`TERMINAL_INACTIVE`,
     * `TERMINAL_HAS_OPEN_SHIFT`) so the device's existing error handling reads
     * it without a new branch.
     */
    private function posDisabledResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'LOCATION_POS_DISABLED',
                'message' => 'POS is not enabled at this location. Enable POS for the location in Settings before using a terminal there.',
            ],
        ], 422);
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
