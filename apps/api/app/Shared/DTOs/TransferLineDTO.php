<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final class TransferLineDTO
{
    /** @param numeric-string $quantity */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly string $quantity,
    ) {}
}
