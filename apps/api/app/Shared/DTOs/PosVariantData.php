<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class PosVariantData
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $sku,
        public ?string $barcode,
        public string $nameSuffix,
        public bool $isDefault,
        public int $displayOrder,
        public ?string $priceOverride, // decimal string or null
        public ?string $imageUrl,
        public ?string $updatedAt,     // ISO-8601 or null
    ) {}
}
