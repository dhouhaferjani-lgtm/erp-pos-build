<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event dispatched when all non-cancelled lines on an order are Ready.
 */
final class OrderReady
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
    ) {}
}
