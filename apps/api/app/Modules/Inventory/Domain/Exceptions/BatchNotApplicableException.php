<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * The named lot does not belong to this line's product, or holds no BatchStock
 * row at the header's location (DPA V7 / D1b part 2).
 */
class BatchNotApplicableException extends DomainException
{
    public function __construct(
        public readonly string $productId,
        public readonly string $batchUuid,
    ) {
        parent::__construct("Lot {$batchUuid} is not applicable to product {$productId} at this location.");
    }
}
