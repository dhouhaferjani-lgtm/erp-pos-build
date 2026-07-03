<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class TrackingIdHolderDTO
{
    public function __construct(
        public string $productId,
        public string $productName,
    ) {}
}
