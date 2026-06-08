<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a stock movement is recorded — version 2.
 *
 * Supersedes StockMovementRecorded (V1) which lacked the variant_id dimension.
 * Both V1 and V2 are fired together (dual-dispatch) so existing V1 subscribers
 * (e.g. Channel\...\DispatchStockChangeToChannels) keep working unchanged while
 * variant-aware subscribers can migrate to V2.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create StockMovementRecordedV3.
 */
final class StockMovementRecordedV2 extends DomainEvent
{
    public function __construct(
        public readonly string $movementId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $movementType,
        public readonly string $quantity,
        public readonly string $unitCost,
        public readonly string $totalCost,
        public readonly string $newStockLevel,
        public readonly ?string $variantId = null,
        public readonly ?string $reference = null,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
        public readonly string $occurredAt = '',
    ) {
        parent::__construct($movementId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'inventory.stock_movement.recorded.v2';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, mixed>
     */
    public function getAuditData(): array
    {
        return [
            'movement_id' => $this->movementId,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'location_id' => $this->locationId,
            'movement_type' => $this->movementType,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'new_stock_level' => $this->newStockLevel,
            'reference' => $this->reference,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
        ];
    }
}
