<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\DTOs;

final readonly class StockUpdateDTO
{
    public function __construct(
        public string $channelId,
        public string $productId,
        public ?string $variantId,
        public string $locationId,
        public string $quantity,
        public string $idempotencyKey,
    ) {}
}
