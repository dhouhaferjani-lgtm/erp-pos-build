<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

/**
 * Batch allocation for one stock transfer line.
 */
final class InitiateTransferBatchAllocationData
{
    /**
     * @param  numeric-string  $quantity
     */
    public function __construct(
        public readonly int $batchId,
        public readonly string $quantity,
    ) {}
}
