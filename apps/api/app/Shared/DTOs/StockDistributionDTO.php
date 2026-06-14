<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * Cross-location stock distribution for one product (+ optional variant)
 * across all active shop+warehouse locations of a company (Task B4).
 *
 * Totals are quantity-scale-4 numeric STRINGS summed across the rows.
 */
final readonly class StockDistributionDTO
{
    /**
     * @param  string  $productId  UUID of the product
     * @param  string|null  $variantId  UUID of the variant (null = product-grain)
     * @param  string|null  $variantLabel  Optional human-readable variant label
     * @param  list<StockDistributionRowDTO>  $locations  One row per active shop/warehouse location, current first then by name
     * @param  string  $totalOnHand  Sum of on-hand across locations, numeric string at scale 4
     * @param  string  $totalIncomingTransfer  Sum of in-transit incoming across locations, numeric string at scale 4
     */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public ?string $variantLabel,
        public array $locations,
        public string $totalOnHand,
        public string $totalIncomingTransfer,
    ) {}
}
