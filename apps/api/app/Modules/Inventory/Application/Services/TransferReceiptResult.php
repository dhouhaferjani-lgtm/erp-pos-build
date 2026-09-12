<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferReceipt;

final readonly class TransferReceiptResult
{
    public function __construct(public StockTransferReceipt $receipt, public StockTransfer $transfer, public bool $replayed) {}
}
