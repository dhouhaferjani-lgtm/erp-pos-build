<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class ProductUpsertResultData
{
    public function __construct(
        public string $productId,
        public string $sku,
        public bool $skuWasGenerated,
    ) {}
}
