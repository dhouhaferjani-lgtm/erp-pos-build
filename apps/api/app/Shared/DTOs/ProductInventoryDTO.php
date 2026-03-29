<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * Product inventory data for cross-module catalog enrichment.
 */
final readonly class ProductInventoryDTO
{
    public function __construct(
        public string $productId,
        public string $platformArticleId,
        public string $totalStock,
        public string $totalReserved,
        public string $available,
        public ?string $salePrice,
    ) {}
}
