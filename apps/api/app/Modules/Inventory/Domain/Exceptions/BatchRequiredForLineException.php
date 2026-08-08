<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * A NEGATIVE correction line did not name a lot, but the product holds at least
 * one lot with stock at the header's location (DPA V7 / D1b part 2).
 *
 * Deliberately FLAG-INDEPENDENT: the predicate is "a lot with stock exists
 * here", not `products.requires_batch_tracking`. The flag is user-toggleable and
 * nothing deletes BatchStock on the reverse flip, so keying this rule on it would
 * forbid naming the lot that actually holds the stock while the aggregate moved
 * and the lots did not — gate finding C2's desync, re-created through the other
 * door.
 */
class BatchRequiredForLineException extends DomainException
{
    public function __construct(public readonly string $productId)
    {
        parent::__construct("A negative adjustment for product {$productId} must name the lot it draws down.");
    }
}
