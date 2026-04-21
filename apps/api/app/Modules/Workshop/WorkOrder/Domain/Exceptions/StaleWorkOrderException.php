<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Exceptions;

use DomainException;

/**
 * Raised when an optimistic-concurrency check fails during status transition:
 * the caller supplied an expected `updated_at` that no longer matches the
 * current row. Maps to HTTP 409 with a `ConflictDetail { code:
 * 'WORK_ORDER_STALE', current_updated_at, expected_updated_at }` body at the
 * API boundary.
 */
final class StaleWorkOrderException extends DomainException
{
    public function __construct(
        public readonly string $workOrderId,
        public readonly \DateTimeImmutable $expectedUpdatedAt,
        public readonly \DateTimeImmutable $currentUpdatedAt,
    ) {
        parent::__construct(
            "WorkOrder {$workOrderId} is stale: expected updated_at {$expectedUpdatedAt->format(DATE_ATOM)} but current is {$currentUpdatedAt->format(DATE_ATOM)}."
        );
    }
}
