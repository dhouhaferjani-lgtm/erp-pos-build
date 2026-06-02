<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * One line on a new stock transfer.
 */
final class InitiateTransferLineData
{
    /**
     * @param  numeric-string  $quantity
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $quantity,
    ) {}
}
