<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Exceptions;

/**
 * Thrown when an atomic, strict-fulfillment FEFO consume cannot satisfy the
 * requested quantity from available (lockable) batch stock.
 *
 * Carries the unfulfilled `$shortfall` (decimal string, 4dp) so callers can
 * report exactly how much could not be allocated. Because the consume runs in
 * a transaction, throwing this rolls back every batch decrement and movement
 * insert from the same pass — the operation is all-or-nothing.
 */
final class InsufficientBatchStockException extends \DomainException
{
    /**
     * @param  numeric-string  $shortfall  Unfulfilled quantity (decimal string, 4dp)
     */
    public function __construct(
        public readonly string $shortfall,
        ?string $message = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? "Insufficient batch stock to fulfill atomic consume. Shortfall: {$shortfall}",
            $code,
            $previous,
        );
    }
}
