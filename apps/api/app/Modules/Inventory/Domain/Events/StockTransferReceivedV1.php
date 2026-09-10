<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class StockTransferReceivedV1 extends DomainEvent
{
    /**
     * @param  list<int>  $lineEventVersions
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly string $receiptNumber,
        public readonly int $sequence,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $receivedByUserId,
        public readonly bool $isBlind,
        public readonly bool $hasDiscrepancy,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly int $lineCount,
        public readonly string $totalReceived,
        public readonly string $totalDamaged,
        public readonly ?string $receiptNotes,
        public readonly string $idempotencyKey,
        public readonly string $payloadHash,
        public readonly string $freightUncapitalized,
        public readonly array $lineEventVersions,
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
        return 'inventory.stock_transfer.received.v1';
    }
}
