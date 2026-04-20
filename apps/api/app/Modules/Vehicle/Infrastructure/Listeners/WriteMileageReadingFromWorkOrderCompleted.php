<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Listeners;

/**
 * Stub: fires when Plan B (Workshop / Work Order) ships the WorkOrderCompleted event.
 *
 * Intentionally does NOT use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted
 * because that class does not exist until Plan B lands — importing it would break autoload.
 *
 * This class is NOT registered in EventServiceProvider::$listen until Plan B merges.
 * When Plan B lands, add the binding:
 *
 *   \App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompleted::class => [
 *       \App\Modules\Vehicle\Infrastructure\Listeners\WriteMileageReadingFromWorkOrderCompleted::class,
 *   ],
 *
 * and at that point the parameter type can be tightened from `object` to the concrete event class.
 */
final readonly class WriteMileageReadingFromWorkOrderCompleted
{
    public function handle(object $event): void
    {
        // No-op until Plan B merges. Stub kept so listener wiring lands incrementally.
        unset($event);
    }
}
