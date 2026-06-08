<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\DTOs;

use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;

/**
 * One batch consumed by an atomic FEFO consumption pass.
 *
 * Lives in the Domain tier so {@see FEFOInventoryService::consumeBatchesAtomically()}
 * may return it without crossing the Domain → Application deptrac boundary.
 */
final readonly class ConsumedBatchDTO
{
    public function __construct(
        public int $batchId,
        public int $batchStockId,
        /** @var numeric-string Quantity drawn from this batch (decimal string, 4dp). */
        public string $quantityConsumed,
        public \DateTimeInterface $expiryDate,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'batch_stock_id' => $this->batchStockId,
            'quantity_consumed' => $this->quantityConsumed,
            'expiry_date' => $this->expiryDate->format('Y-m-d'),
        ];
    }
}
