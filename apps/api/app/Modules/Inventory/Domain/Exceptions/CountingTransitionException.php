<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\CountingStatus;
use DomainException;

/**
 * Thrown when an inventory-counting status transition is refused — either the
 * edge does not exist in `CountingStatus::allowedTransitions()`, or a locked
 * re-read found the counting somewhere other than the status the caller
 * observed before it took the lock.
 *
 * Replaces the bare `\InvalidArgumentException` that `InventoryCounting::
 * transitionTo()` used to throw. That type has no render handler in
 * `bootstrap/app.php`, so a lost finalize race or a late count submission
 * surfaced to the counter as a 500. Extending `DomainException` routes it
 * through the generic 422 BUSINESS_ERROR handler, matching this module's
 * existing shape (`TransferStateException`, `OverlappingCountingException`)
 * and `WorkOrderTransitionException` in Workshop.
 */
class CountingTransitionException extends DomainException
{
    public function __construct(
        public readonly string $countingId,
        public readonly CountingStatus $currentStatus,
        public readonly CountingStatus $attemptedStatus,
    ) {
        parent::__construct(
            "Cannot transition counting {$countingId} from {$currentStatus->value} to {$attemptedStatus->value}"
        );
    }
}
