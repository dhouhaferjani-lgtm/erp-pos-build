<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an inventory counting session is completed.
 *
 * This event is dispatched when a physical inventory count is finalized
 * and stock adjustments are created for all variances.
 *
 * Key for fraud detection: Large variances or frequent counting completions
 * may indicate inventory shrinkage or manipulation.
 */
final class InventoryCountingCompleted extends DomainEvent
{
    public function __construct(
        public readonly string $countingId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $countingNumber,
        public readonly int $itemsCount,
        public readonly string $totalVariance,
        public readonly string $completedBy,
        public readonly string $completedAt,
    ) {
        parent::__construct($countingId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'inventory.counting.completed';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, mixed>
     */
    public function getAuditData(): array
    {
        return [
            'counting_id' => $this->countingId,
            'counting_number' => $this->countingNumber,
            'location_id' => $this->locationId,
            'items_count' => $this->itemsCount,
            'total_variance' => $this->totalVariance,
            'completed_by' => $this->completedBy,
            'completed_at' => $this->completedAt,
        ];
    }
}
