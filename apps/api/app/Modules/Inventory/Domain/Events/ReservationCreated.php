<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when stock is reserved for a source (sales order, cart, etc.).
 *
 * This event is part of the audit trail for stock reservations.
 * It enables fraud detection and operational tracking.
 *
 * Key use cases:
 * - Track when and why stock was reserved
 * - Monitor reservation patterns for fraud detection
 * - Audit trail for high-value reservations
 * - Trigger automatic inventory counts if thresholds exceeded
 */
final class ReservationCreated extends DomainEvent
{
    public function __construct(
        public readonly string $reservationId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $quantity,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly ?string $sourceLineId,
        public readonly ?string $expiresAt,
        public readonly int $priority,
        public readonly string $createdBy,
        public readonly string $createdAt,
    ) {
        parent::__construct($reservationId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'reservation.created';
    }
}
