<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Exceptions;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use DomainException;

/**
 * Raised when a requested status transition is not allowed — either because
 * the adjacency map forbids it (see `StatusMachine`) or because a policy
 * (see `StatusTransitionPolicyInterface`) vetoes an otherwise-legal edge.
 *
 * Maps to HTTP 422 at the API boundary (see Task 17 controllers).
 */
final class WorkOrderTransitionException extends DomainException
{
    public static function forbiddenEdge(WorkOrderStatus $from, WorkOrderStatus $to): self
    {
        return new self(
            "WorkOrder status transition {$from->value} → {$to->value} is not allowed."
        );
    }

    public static function vetoedByPolicy(WorkOrderStatus $from, WorkOrderStatus $to, string $reason): self
    {
        return new self(
            "WorkOrder status transition {$from->value} → {$to->value} blocked by policy: {$reason}"
        );
    }
}
