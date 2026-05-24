<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\DTOs;

final readonly class OrderStatusUpdateDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public ?string $trackingNumber = null,
        public ?string $carrier = null,
        public array $metadata = [],
    ) {}
}
