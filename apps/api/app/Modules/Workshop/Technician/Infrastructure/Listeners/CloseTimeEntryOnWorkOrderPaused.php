<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Listeners;

use App\Modules\Workshop\Technician\Application\Services\TimeEntryService;

/**
 * Subscribes to Plan B's `WorkOrderPaused` event. Closes the open time entry for the
 * work order with duration = paused_at - started_at. No-op if there is no open entry
 * for the work order (defensive; covers manual close + rapid pause races).
 *
 * Expected event shape:
 *   public string $work_order_id;
 *   public string $reason_code;
 *   public \DateTimeImmutable $paused_at;
 */
final readonly class CloseTimeEntryOnWorkOrderPaused
{
    public function __construct(
        private TimeEntryService $timeEntryService,
    ) {}

    public function handle(object $event): void
    {
        /** @var string $workOrderId */
        $workOrderId = $event->work_order_id; // @phpstan-ignore property.notFound
        /** @var \DateTimeImmutable $pausedAt */
        $pausedAt = $event->paused_at; // @phpstan-ignore property.notFound

        $this->timeEntryService->closeForWorkOrder($workOrderId, $pausedAt);
    }
}
