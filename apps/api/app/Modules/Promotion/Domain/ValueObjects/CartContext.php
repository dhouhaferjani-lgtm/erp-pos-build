<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\ValueObjects;

/**
 * Represents the current cart state for promotion evaluation.
 */
final readonly class CartContext
{
    /**
     * @param  array<int, CartItemContext>  $items
     * @param  numeric-string  $subtotal
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public array $items,
        public string $subtotal,
        public string $appliedAt,
    ) {}
}
