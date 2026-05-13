<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Listeners;

use App\Modules\Workshop\Technician\Application\Services\TimeEntryService;

/**
 * Subscribes to `WorkOrderCompletedV2`. Closes the open time entry for the
 * work order with duration = completed_at - started_at and emits `TechnicianTimeEntryClosed`.
 *
 * Expected event shape:
 *   public string $work_order_id;
 *   public ?int $completion_mileage;
 *   public \DateTimeImmutable $completed_at;
 */
final readonly class CloseTimeEntryOnWorkOrderCompleted
{
    public function __construct(
        private TimeEntryService $timeEntryService,
    ) {}

    public function handle(object $event): void
    {
        /** @var string $workOrderId */
        $workOrderId = $event->work_order_id; // @phpstan-ignore property.notFound
        /** @var \DateTimeImmutable $completedAt */
        $completedAt = $event->completed_at; // @phpstan-ignore property.notFound

        $this->timeEntryService->closeForWorkOrder($workOrderId, $completedAt);
    }
}
