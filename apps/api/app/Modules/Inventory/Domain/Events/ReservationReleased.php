<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a stock reservation is released (fulfilled, cancelled, or manually released).
 *
 * This event is part of the audit trail for stock reservations.
 * It enables fraud detection and operational tracking.
 *
 * Key use cases:
 * - Track when and why reservations were released
 * - Monitor suspicious release patterns (expired, manual releases)
 * - Audit trail for cancelled orders
 * - Compliance tracking for inventory management
 */
final class ReservationReleased extends DomainEvent
{
    public function __construct(
        public readonly string $reservationId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $quantity,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $releaseReason,
        public readonly ?string $releasedBy,
        public readonly string $releasedAt,
    ) {
        parent::__construct($reservationId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'reservation.released';
    }
}
