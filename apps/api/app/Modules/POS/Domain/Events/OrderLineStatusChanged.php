<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event dispatched when an order line status changes in the kitchen workflow.
 */
final class OrderLineStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $lineId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {}
}
