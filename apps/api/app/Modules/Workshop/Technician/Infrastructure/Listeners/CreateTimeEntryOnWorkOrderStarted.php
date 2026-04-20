<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Infrastructure\Listeners;

use App\Modules\Workshop\Technician\Application\Services\TimeEntryService;

/**
 * Subscribes to Plan B's `WorkOrderStarted` event. Opens a new `work_order`-typed time entry
 * for the primary technician. The event class lives in the Plan B (`Workshop\WorkOrder`) module
 * — this listener intentionally type-hints `object $event` and accesses properties by duck-typing
 * so Plan C does not depend on Plan B's schema. Registration in `EventServiceProvider::$listen`
 * is kept commented out until Plan B lands the event class.
 *
 * Expected event shape (from the cross-plan coordination note):
 *   public string $work_order_id;
 *   public string $primary_technician_profile_id;
 *   public \DateTimeImmutable $started_at;
 */
final readonly class CreateTimeEntryOnWorkOrderStarted
{
    public function __construct(
        private TimeEntryService $timeEntryService,
    ) {}

    public function handle(object $event): void
    {
        /** @var string $workOrderId */
        $workOrderId = $event->work_order_id; // @phpstan-ignore property.notFound
        /** @var string $profileId */
        $profileId = $event->primary_technician_profile_id; // @phpstan-ignore property.notFound
        /** @var \DateTimeImmutable $startedAt */
        $startedAt = $event->started_at; // @phpstan-ignore property.notFound

        $this->timeEntryService->start($profileId, $workOrderId, $startedAt);
    }
}
