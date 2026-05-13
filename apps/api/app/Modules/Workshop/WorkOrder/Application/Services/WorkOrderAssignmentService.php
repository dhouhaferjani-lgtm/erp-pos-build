<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Workshop\Technician\Application\Contracts\TechnicianAvailabilityServiceInterface;
use App\Modules\Workshop\Technician\Domain\ValueObjects\AvailabilityResult;
use App\Modules\Workshop\WorkOrder\Application\Commands\AssignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\SetPrimaryTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UnassignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Technician assignment operations against a WorkOrder.
 *
 * Consults {@see TechnicianAvailabilityServiceInterface} for a soft-conflict
 * check — the result (yes/no/partial) is recorded on the assignment `notes`
 * column so managers can see the warning without the scheduler (Plan D)
 * blocking the assignment itself. The scheduler is the hard enforcer.
 *
 * Exactly one active lead may exist per WO (enforced by the partial unique
 * index on `workshop_work_order_assignments`).
 */
final readonly class WorkOrderAssignmentService
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private TechnicianAvailabilityServiceInterface $availability,
        private ConnectionInterface $db,
    ) {}

    public function assign(AssignTechnicianCommand $command): WorkOrderAssignment
    {
        return $this->db->transaction(function () use ($command): WorkOrderAssignment {
            $wo = $this->requireWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);

            $availabilityNote = $this->probeAvailability($wo, $command->technician_profile_id);

            if ($command->is_lead) {
                $this->unassignCurrentLead($wo->id);
                $wo->primary_technician_profile_id = $command->technician_profile_id;
                $this->workOrders->save($wo);
            }

            $assignment = new WorkOrderAssignment;
            $assignment->fill([
                'tenant_id' => $wo->tenant_id,
                'work_order_id' => $wo->id,
                'technician_profile_id' => $command->technician_profile_id,
                'is_lead' => $command->is_lead,
                'assigned_at' => Carbon::now(),
                'unassigned_at' => null,
                'assigned_by_user_id' => $command->assigned_by_user_id,
                'notes' => $availabilityNote !== null
                    ? ($command->notes !== null
                        ? $command->notes."\n".$availabilityNote
                        : $availabilityNote)
                    : $command->notes,
            ]);
            $assignment->save();

            return $assignment;
        });
    }

    public function unassign(UnassignTechnicianCommand $command): void
    {
        $this->db->transaction(function () use ($command): void {
            $wo = $this->requireWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);

            $assignment = WorkOrderAssignment::query()
                ->where('work_order_id', $wo->id)
                ->where('id', $command->assignment_id)
                ->first();

            if ($assignment === null) {
                throw new RuntimeException("Assignment {$command->assignment_id} not found on WO {$wo->id}.");
            }

            if ($assignment->unassigned_at !== null) {
                return; // idempotent
            }

            $assignment->unassigned_at = Carbon::now();
            $assignment->save();

            if ($assignment->is_lead && $wo->primary_technician_profile_id === $assignment->technician_profile_id) {
                $wo->primary_technician_profile_id = null;
                $this->workOrders->save($wo);
            }
        });
    }

    public function setPrimary(SetPrimaryTechnicianCommand $command): WorkOrderAssignment
    {
        return $this->db->transaction(function () use ($command): WorkOrderAssignment {
            $wo = $this->requireWorkOrder($command->tenant_id, $command->company_id, $command->work_order_id);

            // Drop any current lead on this WO.
            $this->unassignCurrentLead($wo->id);

            // Mark an existing active assignment as lead, or create one.
            /** @var WorkOrderAssignment|null $assignment */
            $assignment = WorkOrderAssignment::query()
                ->where('work_order_id', $wo->id)
                ->where('technician_profile_id', $command->technician_profile_id)
                ->whereNull('unassigned_at')
                ->first();

            if ($assignment === null) {
                $assignment = new WorkOrderAssignment;
                $assignment->fill([
                    'tenant_id' => $wo->tenant_id,
                    'work_order_id' => $wo->id,
                    'technician_profile_id' => $command->technician_profile_id,
                    'is_lead' => true,
                    'assigned_at' => Carbon::now(),
                    'unassigned_at' => null,
                    'assigned_by_user_id' => $wo->opened_by_user_id,
                    'notes' => null,
                ]);
            } else {
                $assignment->is_lead = true;
            }
            $assignment->save();

            $wo->primary_technician_profile_id = $command->technician_profile_id;
            $this->workOrders->save($wo);

            return $assignment;
        });
    }

    private function probeAvailability(WorkOrder $wo, string $technicianProfileId): ?string
    {
        // Use the WO's scheduled window if present; otherwise use "now + 60 minutes"
        // as a lightweight probe window. This is a SOFT check — partial/no outcomes
        // are recorded as warnings on the assignment but never block the assign.
        $startsAt = $wo->scheduled_start_at instanceof Carbon
            ? $wo->scheduled_start_at->toDateTimeImmutable()
            : new \DateTimeImmutable;
        $durationMinutes = $wo->scheduled_start_at instanceof Carbon && $wo->scheduled_end_at instanceof Carbon
            ? max(1, (int) $wo->scheduled_start_at->diffInMinutes($wo->scheduled_end_at))
            : 60;

        $result = $this->availability->isAvailable(
            profileId: $technicianProfileId,
            startsAt: $startsAt,
            durationMinutes: $durationMinutes,
            requiredSpecialty: null,
        );

        return $this->formatAvailabilityWarning($result);
    }

    private function formatAvailabilityWarning(AvailabilityResult $result): ?string
    {
        if ($result->isYes()) {
            return null;
        }

        return '[availability-warning: '.$result->status.':'.$result->reason.']';
    }

    private function unassignCurrentLead(string $workOrderId): void
    {
        WorkOrderAssignment::query()
            ->where('work_order_id', $workOrderId)
            ->where('is_lead', true)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => Carbon::now(), 'is_lead' => false]);
    }

    private function requireWorkOrder(string $tenantId, string $companyId, string $workOrderId): WorkOrder
    {
        $wo = $this->workOrders->findForUpdateForScope($tenantId, $companyId, $workOrderId);
        if ($wo === null) {
            throw new RuntimeException("WorkOrder {$workOrderId} not found.");
        }

        return $wo;
    }
}
