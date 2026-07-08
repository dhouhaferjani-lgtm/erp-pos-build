<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;

final class ReviewedBatchData extends Data
{
    public function __construct(
        public string $batchNumber,
        public string $expiryDate,
        public ?string $manufacturingDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            batchNumber: (string) $payload['batchNumber'],
            expiryDate: (string) $payload['expiryDate'],
            manufacturingDate: isset($payload['manufacturingDate']) ? (string) $payload['manufacturingDate'] : null,
        );
    }

    /**
     * @return array{batch_number: string, expiry_date: string, manufacturing_date?: string}
     */
    public function toReceiptArray(): array
    {
        $batch = [
            'batch_number' => $this->batchNumber,
            'expiry_date' => $this->expiryDate,
        ];

        if ($this->manufacturingDate !== null) {
            $batch['manufacturing_date'] = $this->manufacturingDate;
        }

        return $batch;
    }
}
