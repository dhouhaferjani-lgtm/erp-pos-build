<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class StockTransferReceiptPosted
{
    use Dispatchable;

    public function __construct(
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly ?string $disposition,
        public readonly bool $hasDiscrepancy,
        public readonly int $linesWithDiscrepancy,
        public readonly string $actorUserId,
        public readonly string $destinationLocationId,
        public readonly string $receiptNumber,
        public readonly string $transferNumber,
    ) {}
}
