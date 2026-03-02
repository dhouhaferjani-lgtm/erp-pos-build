<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\ValueObjects;

/**
 * A single item in the cart for promotion evaluation.
 */
final readonly class CartItemContext
{
    /**
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $lineTotal
     */
    public function __construct(
        public string $productId,
        public ?string $categoryId,
        public int $quantity,
        public string $unitPrice,
        public string $lineTotal,
    ) {}
}
