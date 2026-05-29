<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a stock reservation expires — version 2.
 *
 * Supersedes ReservationExpired (V1) which lacked the variant dimension. Adds
 * variantId. Both V1 and V2 are fired together (dual-dispatch) so existing
 * listeners are not broken.
 *
 * IMPORTANT: This event is immutable. Never rename or change its structure.
 * If requirements change, create ReservationExpiredV3.
 */
final class ReservationExpiredV2 extends DomainEvent
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
        public readonly ?string $variantId = null,
    ) {
        parent::__construct($reservationId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'reservation.expired.v2';
    }
}
