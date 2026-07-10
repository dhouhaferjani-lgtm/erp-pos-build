<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

final class DraftPurchaseOrderLineData
{
    /** @param numeric-string $quantity */
    public function __construct(
        public readonly string $productId,
        public readonly string $quantity,
        public readonly ?string $variantId,
        public readonly ?string $lineLocationId,
    ) {}
}
