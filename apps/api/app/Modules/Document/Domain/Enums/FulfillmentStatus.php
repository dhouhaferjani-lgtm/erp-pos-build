<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Fulfillment Status for Sales Documents
 *
 * Tracks delivery/shipment status for invoices and sales orders.
 * Computed from delivery note associations and line quantities.
 *
 * @see Document::getFulfillmentStatus()
 */
enum FulfillmentStatus: string
{
    /**
     * Nothing has been delivered yet
     */
    case NotFulfilled = 'not_fulfilled';

    /**
     * Some items delivered, but not all
     */
    case PartiallyFulfilled = 'partially_fulfilled';

    /**
     * All items have been delivered
     */
    case Fulfilled = 'fulfilled';

    /**
     * Document doesn't require fulfillment (e.g., service-only invoice)
     */
    case NotApplicable = 'not_applicable';

    /**
     * Get display label for the status
     */
    public function label(): string
    {
        return match ($this) {
            self::NotFulfilled => 'Not Fulfilled',
            self::PartiallyFulfilled => 'Partially Fulfilled',
            self::Fulfilled => 'Fulfilled',
            self::NotApplicable => 'N/A',
        };
    }

    /**
     * Get color class for badge display
     */
    public function color(): string
    {
        return match ($this) {
            self::NotFulfilled => 'gray',
            self::PartiallyFulfilled => 'yellow',
            self::Fulfilled => 'green',
            self::NotApplicable => 'gray',
        };
    }

    /**
     * Check if any fulfillment is pending
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::NotFulfilled, self::PartiallyFulfilled => true,
            self::Fulfilled, self::NotApplicable => false,
        };
    }

    /**
     * Check if fulfillment is complete
     */
    public function isComplete(): bool
    {
        return $this === self::Fulfilled;
    }
}
