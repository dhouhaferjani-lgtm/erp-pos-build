<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StockTransferCompleted
{
    use Dispatchable;

    /**
     * @param  numeric-string  $transferCost
     */
    public function __construct(
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $transferCost,
        public readonly string $completedByUserId,
        public readonly string $occurredAt,
    ) {}
}
