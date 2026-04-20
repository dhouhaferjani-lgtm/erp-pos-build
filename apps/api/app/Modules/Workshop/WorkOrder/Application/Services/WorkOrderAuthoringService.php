<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Services;

use App\Modules\Workshop\WorkOrder\Application\Commands\CreateWorkOrderCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\RecordDiagnosisCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UpdateWorkOrderHeaderCommand;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderSequenceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCreated;
use App\Modules\Workshop\WorkOrder\Domain\Exceptions\WorkOrderImmutableException;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Authoring-only service for the WorkOrder aggregate.
 *
 * Owns: create / update header / record diagnosis. Does NOT touch status
 * (that's WorkOrderTransitionService) and does NOT touch lines (that's
 * WorkOrderLineService). Refuses writes on terminal / read-only states.
 */
final readonly class WorkOrderAuthoringService
{
    public function __construct(
        private WorkOrderRepositoryInterface $workOrders,
        private WorkOrderSequenceInterface $sequence,
        private ConnectionInterface $db,
    ) {}

    public function create(CreateWorkOrderCommand $command): WorkOrder
    {
        return $this->db->transaction(function () use ($command): WorkOrder {
            $number = $this->sequence->next($command->company_id);

            $wo = new WorkOrder;
            $wo->fill([
                'tenant_id' => $command->tenant_id,
                'company_id' => $command->company_id,
                'location_id' => $command->location_id,
                'work_order_number' => $number,
                'status' => WorkOrderStatus::Received->value,
                'type' => $command->type->value,
                'customer_partner_id' => $command->customer_partner_id,
                'vehicle_id' => $command->vehicle_id,
                'opened_by_user_id' => $command->opened_by_user_id,
                'primary_technician_profile_id' => $command->primary_technician_profile_id,
                'mileage_at_intake' => $command->mileage_at_intake,
                'customer_complaint' => $command->customer_complaint,
                'internal_notes' => $command->internal_notes,
                'scheduled_start_at' => $command->scheduled_start_at,
                'scheduled_end_at' => $command->scheduled_end_at,
                'promised_at' => $command->promised_at,
                'currency' => $command->currency,
                'estimated_parts_total' => '0.000',
                'estimated_labor_total' => '0.000',
                'estimated_other_total' => '0.000',
                'estimated_tax_total' => '0.000',
                'estimated_grand_total' => '0.000',
                'actual_parts_total' => '0.000',
                'actual_labor_total' => '0.000',
                'actual_other_total' => '0.000',
                'actual_tax_total' => '0.000',
                'actual_grand_total' => '0.000',
            ]);

            $wo = $this->workOrders->save($wo);

            event(new WorkOrderCreated(
                work_order_id: $wo->id,
                vehicle_id: $wo->vehicle_id,
                customer_partner_id: $wo->customer_partner_id,
                type: $command->type,
                created_at: new \DateTimeImmutable,
            ));

            return $wo;
        });
    }

    public function updateHeader(UpdateWorkOrderHeaderCommand $command): WorkOrder
    {
        return $this->db->transaction(function () use ($command): WorkOrder {
            $wo = $this->requireMutableWorkOrder($command->work_order_id);

            if ($command->primary_technician_profile_id !== null) {
                $wo->primary_technician_profile_id = $command->primary_technician_profile_id;
            }
            if ($command->diagnosis !== null) {
                $wo->diagnosis = $command->diagnosis;
            }
            if ($command->internal_notes !== null) {
                $wo->internal_notes = $command->internal_notes;
            }
            if ($command->scheduled_start_at !== null) {
                $wo->scheduled_start_at = \Illuminate\Support\Carbon::instance($command->scheduled_start_at);
            }
            if ($command->scheduled_end_at !== null) {
                $wo->scheduled_end_at = \Illuminate\Support\Carbon::instance($command->scheduled_end_at);
            }
            if ($command->promised_at !== null) {
                $wo->promised_at = \Illuminate\Support\Carbon::instance($command->promised_at);
            }

            return $this->workOrders->save($wo);
        });
    }

    public function recordDiagnosis(RecordDiagnosisCommand $command): WorkOrder
    {
        return $this->db->transaction(function () use ($command): WorkOrder {
            $wo = $this->requireMutableWorkOrder($command->work_order_id);
            $wo->diagnosis = $command->diagnosis;

            return $this->workOrders->save($wo);
        });
    }

    private function requireMutableWorkOrder(string $workOrderId): WorkOrder
    {
        $wo = $this->workOrders->findForUpdate($workOrderId);
        if ($wo === null) {
            throw new RuntimeException("WorkOrder {$workOrderId} not found.");
        }

        if (in_array($wo->status, [
            WorkOrderStatus::Completed,
            WorkOrderStatus::Invoiced,
            WorkOrderStatus::Closed,
            WorkOrderStatus::Cancelled,
        ], true)) {
            throw WorkOrderImmutableException::forStatus($wo->status);
        }

        return $wo;
    }
}
