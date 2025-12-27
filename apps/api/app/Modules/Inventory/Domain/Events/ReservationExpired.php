<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a stock reservation expires due to timeout.
 *
 * This event is part of the audit trail for stock reservations and fraud detection.
 * Expired reservations are suspicious and may indicate problematic behavior patterns.
 *
 * Key use cases:
 * - Track expired reservations for fraud detection
 * - Monitor conversion rates (reservations that expire vs. convert to sales)
 * - Identify problematic customers or patterns
 * - Trigger automatic inventory count if high-value reservations expire
 */
final class ReservationExpired extends DomainEvent
{
    public function __construct(
        public readonly string $reservationId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $quantity,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $originalExpiresAt,
        public readonly string $expiredAt,
    ) {
        parent::__construct($reservationId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'reservation.expired';
    }
}
