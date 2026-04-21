<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Services;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;

/**
 * Pure domain service encoding the WorkOrder status-machine adjacency map
 * from Spec §5.3.
 *
 * This class is intentionally side-effect-free: it answers "is this
 * transition legal?" without touching persistence, events, or adapters.
 * `WorkOrderTransitionService` (Task 12) is the single write path — it
 * consults this machine before every mutation and raises
 * `WorkOrderTransitionException` (422 at the API boundary) when the
 * requested edge is forbidden.
 */
final class StatusMachine
{
    /**
     * Determine whether the given edge is allowed by the adjacency map.
     *
     * - Terminal states (Closed, Cancelled) have no outgoing edges.
     * - Self-loops are always forbidden.
     */
    public function isAllowed(WorkOrderStatus $from, WorkOrderStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return in_array($to, $this->allowedTargetsOf($from), true);
    }

    /**
     * Return the allowed outgoing target states for a source state.
     *
     * @return list<WorkOrderStatus>
     */
    public function allowedTargetsOf(WorkOrderStatus $from): array
    {
        return match ($from) {
            WorkOrderStatus::Received => [
                WorkOrderStatus::Diagnosed,
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::Diagnosed => [
                WorkOrderStatus::Quoted,
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::Quoted => [
                WorkOrderStatus::Approved,
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::Approved => [
                WorkOrderStatus::InProgress,
                WorkOrderStatus::WaitingParts,
                WorkOrderStatus::Quoted,     // re-quote: prior quote superseded
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::InProgress => [
                WorkOrderStatus::Paused,
                WorkOrderStatus::WaitingParts,
                WorkOrderStatus::Completed,
            ],
            WorkOrderStatus::Paused => [
                WorkOrderStatus::InProgress,
                WorkOrderStatus::WaitingParts,
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::WaitingParts => [
                WorkOrderStatus::InProgress,
                WorkOrderStatus::Cancelled,
            ],
            WorkOrderStatus::Completed => [
                WorkOrderStatus::Invoiced,
            ],
            WorkOrderStatus::Invoiced => [
                WorkOrderStatus::Closed,
            ],
            // Terminal states have no outgoing edges.
            WorkOrderStatus::Closed,
            WorkOrderStatus::Cancelled => [],
        };
    }
}
