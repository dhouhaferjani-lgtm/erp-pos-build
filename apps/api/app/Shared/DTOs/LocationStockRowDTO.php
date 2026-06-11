<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * One stock_levels row at the requested location, variant-grain
 * (variantId null = product-grain row).
 */
final readonly class LocationStockRowDTO
{
    /**
     * @param  string  $quantity  Numeric string at quantity scale 4 (e.g. "12.0000")
     * @param  string  $reserved  Numeric string at quantity scale 4
     * @param  string  $available  quantity − reserved, numeric string at scale 4
     * @param  string|null  $updatedAt  ISO-8601 timestamp of the stock row's last update (null only if the row predates timestamps)
     */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $quantity,
        public string $reserved,
        public string $available,
        public ?string $updatedAt,
    ) {}
}
