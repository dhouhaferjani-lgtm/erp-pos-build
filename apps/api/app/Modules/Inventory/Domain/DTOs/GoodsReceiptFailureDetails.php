<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\DTOs;

final readonly class GoodsReceiptFailureDetails
{
    /**
     * @param  list<array{line_number: int, sku: string|null, description: string}>  $lines
     */
    public function __construct(
        public array $lines = [],
        public ?string $ordered = null,
        public ?string $alreadyReceived = null,
        public ?string $requested = null,
        public ?string $remaining = null,
        public ?string $batchNumber = null,
        public ?string $storedExpiry = null,
        public ?string $suppliedExpiry = null,
    ) {}

    /**
     * @return array<string, string|list<array{line_number: int, sku: string|null, description: string}>>
     */
    public function toArray(): array
    {
        $details = [];

        if ($this->lines !== []) {
            $details['lines'] = $this->lines;
        }

        foreach ([
            'ordered' => $this->ordered,
            'already_received' => $this->alreadyReceived,
            'requested' => $this->requested,
            'remaining' => $this->remaining,
            'batch_number' => $this->batchNumber,
            'stored_expiry' => $this->storedExpiry,
            'supplied_expiry' => $this->suppliedExpiry,
        ] as $key => $value) {
            if ($value !== null) {
                $details[$key] = $value;
            }
        }

        return $details;
    }
}
