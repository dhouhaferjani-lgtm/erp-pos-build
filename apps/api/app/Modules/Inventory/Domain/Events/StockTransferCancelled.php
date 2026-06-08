<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Modules\Inventory\Domain\Enums\TransferStatus;
use Illuminate\Foundation\Events\Dispatchable;

class StockTransferCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly TransferStatus $previousStatus,
        public readonly string $cancelledByUserId,
        public readonly ?string $reason,
        public readonly string $occurredAt,
    ) {}
}
