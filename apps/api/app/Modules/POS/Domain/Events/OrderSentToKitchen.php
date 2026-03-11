<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event dispatched when an order is sent to the kitchen.
 *
 * Contains the order ID for downstream listeners (e.g., KDS, notifications).
 */
final class OrderSentToKitchen
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
    ) {}
}
