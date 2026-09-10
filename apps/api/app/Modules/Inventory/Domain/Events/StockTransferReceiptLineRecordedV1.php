<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class StockTransferReceiptLineRecordedV1 extends DomainEvent
{
    /**
     * @param  list<array{receiptLotId: string, batchAllocationId: string, batchId: int, batchNumber: string, quantityReceived: string, quantityDamaged: string, quantityWrittenOff: string, quantityReturned: string, inMovementId: string|null, scrapMovementId: string|null, returnMovementId: string|null}>  $lots
     */
    public function __construct(
        public readonly string $receiptLineId,
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $transferLineId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly ?string $disposition,
        public readonly int $sequence,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $actorUserId,
        public readonly string $actorRole,
        public readonly bool $isBlind,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly bool $isLotTracked,
        public readonly string $quantitySent,
        public readonly string $quantityPreviouslyReceived,
        public readonly string $quantityPreviouslyDamaged,
        public readonly string $quantityReceived,
        public readonly string $quantityDamaged,
        public readonly string $quantityWrittenOff,
        public readonly string $quantityReturned,
        public readonly string $quantityRemainingAfter,
        public readonly ?string $discrepancyReason,
        public readonly ?string $discrepancyNote,
        public readonly ?string $inMovementId,
        public readonly ?string $scrapMovementId,
        public readonly ?string $returnMovementId,
        public readonly array $lots,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurredAt);
    }

    public function eventName(): string
    {
        return 'inventory.stock_transfer.receipt_line.recorded.v1';
    }
}
