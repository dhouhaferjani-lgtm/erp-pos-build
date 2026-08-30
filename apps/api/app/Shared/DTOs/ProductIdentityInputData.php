<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class ProductIdentityInputData
{
    public function __construct(
        public string $key,
        public ?string $sku,
        public ?string $barcode,
        public string $name,
    ) {}
}
