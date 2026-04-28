<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Listeners;

use App\Modules\Vehicle\Application\Commands\LogVehicleMileageCommand;
use App\Modules\Vehicle\Application\Services\VehicleMileageService;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted;

/**
 * Activated listener: writes a VehicleMileageReading row whenever a WorkOrder
 * transitions to Completed with a captured completion mileage.
 *
 * Skips silently (no-op) when:
 *  - `completion_mileage` is null (non-vehicle service or no odometer captured), or
 *  - the WorkOrder cannot be resolved (should not occur in practice but guards the
 *    listener from poisoning the event queue).
 */
final readonly class WriteMileageReadingFromWorkOrderCompleted
{
    public function __construct(
        private VehicleMileageService $mileageService,
        private WorkOrderRepositoryInterface $workOrders,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        if ($event->completion_mileage === null) {
            return;
        }

        $workOrder = $this->workOrders->findById($event->work_order_id);
        if ($workOrder === null) {
            return;
        }

        $this->mileageService->log(new LogVehicleMileageCommand(
            vehicle_id: $workOrder->vehicle_id,
            mileage: $event->completion_mileage,
            recorded_at: $event->completed_at,
            source: MileageSource::WorkOrderCompletion,
            context_document_id: null,
            context_work_order_id: $workOrder->id,
            recorded_by_user_id: null,
            notes: 'Auto-logged from Work Order '.$workOrder->work_order_number,
        ));
    }
}
