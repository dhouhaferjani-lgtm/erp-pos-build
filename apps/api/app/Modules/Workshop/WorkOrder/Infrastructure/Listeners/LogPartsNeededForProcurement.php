<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Listeners;

use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderPartsNeeded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Stub listener for WorkOrderPartsNeeded — logs the structured part-need
 * payload so the future procurement agent has a replay trail.
 *
 * The real procurement agent subscribes by replacing this listener once
 * that service ships; the event signature is frozen per AutoERP Rule #8.
 */
final class LogPartsNeededForProcurement implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WorkOrderPartsNeeded $event): void
    {
        Log::info('workshop.work_order.parts_needed', [
            'work_order_id' => $event->work_order_id,
            'recorded_at' => $event->recorded_at->format(DATE_ATOM),
            'needs' => array_map(
                static fn ($need) => [
                    'product_id' => $need->product_id,
                    'display_name' => $need->display_name,
                    'quantity' => $need->quantity,
                    'unit' => $need->unit,
                    'vehicle_id' => $need->vehicle_id,
                    'vehicle_display' => $need->vehicle_display,
                    'urgency' => $need->urgency,
                    'notes' => $need->notes,
                ],
                $event->needs,
            ),
        ]);
    }
}
