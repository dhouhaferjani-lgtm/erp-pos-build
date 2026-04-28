<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\Listeners;

use App\Modules\Workshop\Technician\Application\Services\TimeEntryService;

/**
 * Subscribes to Plan B's `WorkOrderResumed` event. Reopens the most recently closed
 * time entry for the work order by nulling `ended_at` and `duration_minutes` — does
 * NOT insert a new row. Rationale: pause→resume cycles should appear as a single
 * continuous technician interaction once the work order is eventually closed; the
 * close listener recomputes duration from `started_at` at that point.
 *
 * Lives under Application/Listeners/ (not Infrastructure) because the reopen semantics
 * encode domain intent about what "resumed" means for a technician's clock-in state,
 * not a purely technical side effect.
 *
 * Expected event shape:
 *   public string $work_order_id;
 *   public \DateTimeImmutable $resumed_at;
 */
final readonly class ReopenTimeEntryOnWorkOrderResumed
{
    public function __construct(
        private TimeEntryService $timeEntryService,
    ) {}

    public function handle(object $event): void
    {
        /** @var string $workOrderId */
        $workOrderId = $event->work_order_id; // @phpstan-ignore property.notFound
        /** @var \DateTimeImmutable $resumedAt */
        $resumedAt = $event->resumed_at; // @phpstan-ignore property.notFound

        $this->timeEntryService->reopenMostRecentlyClosedForWorkOrder($workOrderId, $resumedAt);
    }
}
