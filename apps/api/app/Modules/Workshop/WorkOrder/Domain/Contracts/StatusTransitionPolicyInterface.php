<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Contracts;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;

/**
 * Contract for policy-layer checks performed in addition to the raw
 * `StatusMachine` adjacency. A policy may veto a structurally-legal
 * transition (e.g., require an approved cost cap, block cancellation during
 * business hours on certain WO types, require lead technician assignment
 * before InProgress, etc.).
 *
 * The default implementation (`DefaultStatusTransitionPolicy`) is
 * permissive — tenants can swap in richer policies via the service
 * provider when needed.
 */
interface StatusTransitionPolicyInterface
{
    /**
     * Return the policy-level reason blocking the transition, or null when
     * allowed. WorkOrderTransitionService converts a non-null result into
     * a `WorkOrderTransitionException` (422 at the API boundary).
     */
    public function mayTransition(WorkOrder $workOrder, WorkOrderStatus $to): ?string;
}
