<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event dispatched when an order is closed and converted to a receipt.
 *
 * Contains both orderId and receiptId for downstream listeners.
 */
final class OrderClosed
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $receiptId,
    ) {}
}
