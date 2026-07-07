<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;

final class ReviewedLineData extends Data
{
    public function __construct(
        public string $productId,
        public ?string $variantId,
        public string $quantity,
        public ?string $unitPrice,
        public ?string $vatRate,
        public string $freeQuantity,
        public ?ReviewedBatchData $batch,
        public ?string $sourceLineId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $batch = null;
        if (isset($payload['batch']) && is_array($payload['batch'])) {
            /** @var array<string, mixed> $batchPayload */
            $batchPayload = $payload['batch'];
            $batch = ReviewedBatchData::fromArray($batchPayload);
        }

        return new self(
            productId: (string) $payload['productId'],
            variantId: isset($payload['variantId']) ? (string) $payload['variantId'] : null,
            quantity: (string) $payload['quantity'],
            unitPrice: isset($payload['unitPrice']) ? (string) $payload['unitPrice'] : null,
            vatRate: isset($payload['vatRate']) ? (string) $payload['vatRate'] : null,
            freeQuantity: isset($payload['freeQuantity']) ? (string) $payload['freeQuantity'] : '0',
            batch: $batch,
            sourceLineId: isset($payload['sourceLineId']) ? (string) $payload['sourceLineId'] : null,
        );
    }
}
