<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Workshop\Technician\Application\DTOs\TechnicianTimeEntryData;
use App\Modules\Workshop\Technician\Domain\Contracts\TechnicianProfileRepositoryInterface;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Presentation\Requests\StoreTimeEntryRequest;
use App\Modules\Workshop\Technician\Presentation\Requests\UpdateTimeEntryRequest;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD controller for technician time entries.
 *
 * Authorization:
 *  - `index`: `workshop.technicians.view` (route-level).
 *  - `store`: allowed when the caller has `workshop.technicians.manage_time_entries`
 *    OR when the caller is the technician themselves (self-log). The route has
 *    no `can:` gate for this reason — the controller enforces both paths.
 *  - `update`/`destroy`: `workshop.technicians.manage_time_entries` at the
 *    route middleware AND an additional immutability check when the entry is
 *    linked to a WorkOrder in a locked status (Completed or Invoiced).
 *
 * Lock rule: an entry tied to a WO in status Completed or Invoiced returns 422
 * with error code TIME_ENTRY_LOCKED on any write. WO status is queried via the
 * WorkOrderRepositoryInterface — NO direct model import from the WorkOrder
 * module (CLAUDE.md rule #6).
 */
final class TechnicianTimeEntryController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TechnicianProfileRepositoryInterface $profiles,
        private readonly WorkOrderRepositoryInterface $workOrders,
    ) {}

    public function index(Request $request, string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        $query = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $technicianId)
            ->orderBy('started_at');

        $from = $request->query('from');
        if (is_string($from) && $from !== '') {
            $query->where('started_at', '>=', $from);
        }
        $to = $request->query('to');
        if (is_string($to) && $to !== '') {
            $query->where('started_at', '<=', $to.' 23:59:59');
        }

        $rows = $query->get();

        // Batch-resolve WO status for each distinct work_order_id so the DTO
        // can project `work_order_status` without a per-row query. We route
        // through WorkOrderRepositoryInterface (one call per WO) rather than
        // importing the WorkOrder model directly (CLAUDE.md rule #6).
        /** @var array<string, WorkOrderStatus|null> $statusByWorkOrderId */
        $statusByWorkOrderId = [];
        foreach ($rows as $row) {
            $workOrderId = $row->work_order_id;
            if (! is_string($workOrderId) || $workOrderId === '') {
                continue;
            }
            if (array_key_exists($workOrderId, $statusByWorkOrderId)) {
                continue;
            }
            if (! Str::isUuid($workOrderId)) {
                $statusByWorkOrderId[$workOrderId] = null;

                continue;
            }
            $wo = $this->workOrders->findById($workOrderId);
            $statusByWorkOrderId[$workOrderId] = $wo?->status;
        }

        /** @var list<array<string, mixed>> $data */
        $data = $rows
            ->map(static function (TechnicianTimeEntry $r) use ($statusByWorkOrderId): array {
                $status = null;
                $workOrderId = $r->work_order_id;
                if (is_string($workOrderId) && array_key_exists($workOrderId, $statusByWorkOrderId)) {
                    $status = $statusByWorkOrderId[$workOrderId];
                }

                return TechnicianTimeEntryData::fromModel($r, $status)->toArray();
            })
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreTimeEntryRequest $request, string $technicianId): JsonResponse
    {
        if (! Str::isUuid($technicianId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        if ($profile === null) {
            abort(404);
        }

        $user = $request->user();
        $canManage = $user !== null && $user->can('workshop.technicians.manage_time_entries');
        $isSelf = $user !== null && $profile->user_id === $user->getAuthIdentifier();
        if (! $canManage && ! $isSelf) {
            abort(403);
        }

        $companyId = $this->companyContext->requireCompanyId();
        if ($profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var array{work_order_id?: string|null, started_at: string, ended_at: string, entry_type: string, notes?: string|null} $validated */
        $validated = $request->validated();

        // Block starting an entry against an already-locked WO.
        $workOrderId = $validated['work_order_id'] ?? null;
        if (is_string($workOrderId) && $this->isWorkOrderLocked($workOrderId)) {
            return $this->lockedResponse();
        }

        $startedAt = new DateTimeImmutable($validated['started_at']);
        $endedAt = new DateTimeImmutable($validated['ended_at']);
        $duration = (int) round(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60);

        $row = TechnicianTimeEntry::query()->create([
            'tenant_id' => $profile->tenant_id,
            'company_id' => $profile->company_id,
            'technician_profile_id' => $profile->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => $duration,
            'entry_type' => TimeEntryType::from($validated['entry_type']),
            'work_order_id' => $workOrderId,
            'source' => TimeEntrySource::Manual,
            'recorded_by_user_id' => $user?->getAuthIdentifier(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(
            ['data' => TechnicianTimeEntryData::fromModel($row, $this->lookupStatus($row->work_order_id))->toArray()],
            201,
        );
    }

    public function update(
        UpdateTimeEntryRequest $request,
        string $technicianId,
        string $timeEntryId,
    ): JsonResponse {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($timeEntryId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var TechnicianTimeEntry|null $row */
        $row = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $timeEntryId)
            ->first();
        if ($row === null) {
            abort(404);
        }

        if (is_string($row->work_order_id) && $this->isWorkOrderLocked($row->work_order_id)) {
            return $this->lockedResponse();
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        if (isset($validated['entry_type']) && is_string($validated['entry_type'])) {
            $row->entry_type = TimeEntryType::from($validated['entry_type']);
            unset($validated['entry_type']);
        }

        $row->fill($validated);

        if ($row->ended_at !== null) {
            $row->duration_minutes = (int) round(
                ($row->ended_at->getTimestamp() - $row->started_at->getTimestamp()) / 60
            );
        }
        $row->save();

        $refreshed = $row->refresh();

        return response()->json([
            'data' => TechnicianTimeEntryData::fromModel(
                $refreshed,
                $this->lookupStatus($refreshed->work_order_id),
            )->toArray(),
        ]);
    }

    public function destroy(string $technicianId, string $timeEntryId): JsonResponse
    {
        if (! Str::isUuid($technicianId) || ! Str::isUuid($timeEntryId)) {
            abort(404);
        }

        $profile = $this->profiles->findById($technicianId);
        $companyId = $this->companyContext->requireCompanyId();
        if ($profile === null || $profile->company_id !== $companyId) {
            abort(404);
        }

        /** @var TechnicianTimeEntry|null $row */
        $row = TechnicianTimeEntry::query()
            ->where('technician_profile_id', $technicianId)
            ->where('id', $timeEntryId)
            ->first();
        if ($row === null) {
            abort(404);
        }

        if (is_string($row->work_order_id) && $this->isWorkOrderLocked($row->work_order_id)) {
            return $this->lockedResponse();
        }

        $row->delete();

        return response()->json(null, 204);
    }

    private function isWorkOrderLocked(string $workOrderId): bool
    {
        $status = $this->lookupStatus($workOrderId);
        if ($status === null) {
            return false;
        }

        return match ($status) {
            WorkOrderStatus::Completed, WorkOrderStatus::Invoiced => true,
            default => false,
        };
    }

    private function lookupStatus(?string $workOrderId): ?WorkOrderStatus
    {
        if (! is_string($workOrderId) || ! Str::isUuid($workOrderId)) {
            return null;
        }
        $wo = $this->workOrders->findById($workOrderId);

        return $wo?->status;
    }

    private function lockedResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'TIME_ENTRY_LOCKED',
                'message' => 'This time entry is linked to a work order that is completed or invoiced and cannot be modified.',
            ],
        ], 422);
    }
}
