<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Represents the delivery status of a sales order.
 *
 * Used to track how much of a sales order has been delivered:
 * - NotDelivered: No delivery notes created yet
 * - PartiallyDelivered: Some lines have partial or full deliveries
 * - FullyDelivered: All lines have been fully delivered
 */
enum DeliveryStatus: string
{
    case NotDelivered = 'not_delivered';
    case PartiallyDelivered = 'partially_delivered';
    case FullyDelivered = 'fully_delivered';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotDelivered => 'Not Delivered',
            self::PartiallyDelivered => 'Partially Delivered',
            self::FullyDelivered => 'Fully Delivered',
        };
    }

    /**
     * Check if any delivery has been made.
     */
    public function hasDelivery(): bool
    {
        return $this !== self::NotDelivered;
    }

    /**
     * Check if order is completely fulfilled.
     */
    public function isComplete(): bool
    {
        return $this === self::FullyDelivered;
    }
}
