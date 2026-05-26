<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\DTOs;

final readonly class PriceUpdateDTO
{
    public function __construct(
        public string $channelId,
        public string $productId,
        public ?string $variantId,
        public string $price,
        public string $currency,
        public string $idempotencyKey,
    ) {}
}
