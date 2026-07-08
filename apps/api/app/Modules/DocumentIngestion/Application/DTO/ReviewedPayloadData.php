<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;

final class ReviewedPayloadData extends Data
{
    /**
     * @param  list<ReviewedLineData>  $lines
     */
    public function __construct(
        public string $supplierId,
        public ?string $locationId,
        public ?string $reference,
        public ?string $documentDate,
        public array $lines,
        public ?string $currency,
        public ?bool $pendingReceipt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<int, array<string, mixed>> $linePayloads */
        $linePayloads = $payload['lines'];
        /** @var list<ReviewedLineData> $lines */
        $lines = [];
        foreach ($linePayloads as $linePayload) {
            $lines[] = ReviewedLineData::fromArray($linePayload);
        }

        return new self(
            supplierId: (string) $payload['supplierId'],
            locationId: isset($payload['locationId']) ? (string) $payload['locationId'] : null,
            reference: isset($payload['reference']) ? (string) $payload['reference'] : null,
            documentDate: isset($payload['documentDate']) ? (string) $payload['documentDate'] : null,
            lines: $lines,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            pendingReceipt: isset($payload['pendingReceipt']) ? (bool) $payload['pendingReceipt'] : null,
        );
    }
}
