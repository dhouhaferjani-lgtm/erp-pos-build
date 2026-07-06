<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\DTOs;

final readonly class StandaloneReceiptLineInput
{
    /**
     * @param  array{batch_number: string, expiry_date: string, manufacturing_date?: string}|null  $batch
     */
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $quantity,
        public string $freeQuantity,
        public string $unitPrice,
        public ?array $batch = null,
    ) {}
}
