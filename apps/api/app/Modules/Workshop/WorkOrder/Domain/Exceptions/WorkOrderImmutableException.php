<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Exceptions;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use DomainException;

/**
 * Raised when a write (add line, remove line, update header, etc.) is attempted
 * on a WorkOrder in a terminal or read-only state (Completed, Invoiced, Closed,
 * Cancelled). The Application-layer services consult this before mutating.
 */
final class WorkOrderImmutableException extends DomainException
{
    public static function forStatus(WorkOrderStatus $status): self
    {
        return new self(
            "Cannot mutate WorkOrder in status {$status->value}; the aggregate is immutable in this state."
        );
    }
}
