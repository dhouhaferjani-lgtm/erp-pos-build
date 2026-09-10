<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Application\DTOs\StockTransferData;
use App\Modules\Inventory\Domain\StockTransfer;

final class TransferPayloadBuilder
{
    public function build(StockTransfer $transfer, bool $includeLines = false): StockTransferData
    {
        $transfer->loadMissing('sourceLocation', 'destinationLocation', 'initiatedBy', 'completedBy', 'cancelledBy', 'closedBy', 'receipts.receivedBy');
        if ($includeLines) {
            $transfer->loadMissing('lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch');
        }

        return StockTransferData::fromModel($transfer, $includeLines);
    }
}
