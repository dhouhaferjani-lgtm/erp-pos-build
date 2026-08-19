<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use DomainException;

/**
 * `damage` / `write_off` on a batch-tracked product (DPA V7 / D7a).
 *
 * Not a limitation — a REDIRECT to a strictly better path. The adjustment
 * document does not post GL in v1, while BatchWriteOffService chains
 * `createInventoryWriteOffEntry` (Dr Shrinkage / Cr Inventory, keyed on the movement
 * id). Routing a lot-identified destruction through this document would LOSE a
 * journal entry that exists today, so batch-tracked lines are limited to pure
 * quantity corrections.
 *
 * The predicate is `products.requires_batch_tracking` ALONE: deterministic, one
 * query, knowable before any lot lookup. It is explicitly NOT reused for the
 * lot-required / lot-valid rules — see BatchRequiredForLineException.
 */
class UseBatchWriteOffException extends DomainException
{
    public function __construct(
        public readonly string $productId,
        public readonly MovementReason $reasonCode,
    ) {
        parent::__construct(
            "Reason {$reasonCode->value} is not available for batch-tracked product {$productId}: "
            .'use the batch write-off, which posts the shrinkage entry this document does not.'
        );
    }
}
