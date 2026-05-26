<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Events;

final readonly class ProductPublishedToChannel
{
    public function __construct(
        public string $channelId,
        public string $productId,
        public ?string $variantId,
    ) {}
}
