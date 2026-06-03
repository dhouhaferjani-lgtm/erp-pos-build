<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

class StockTransferInitiated
{
    use Dispatchable;

    public function __construct(
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly string $transferType,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $initiatedByUserId,
        public readonly string $occurredAt,
    ) {}
}
