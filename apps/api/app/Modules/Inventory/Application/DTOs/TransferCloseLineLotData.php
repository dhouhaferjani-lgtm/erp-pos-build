<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferReceiptLineLot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A lot row of a kind=close receipt. All four counters are present and non-null. */
#[TypeScript]
final class TransferCloseLineLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public string $quantity_received,
        public string $quantity_damaged,
        public string $quantity_written_off,
        public string $quantity_returned,
    ) {}

    public static function fromModel(StockTransferReceiptLineLot $lot): self
    {
        return new self(
            batch_id: (string) $lot->batch_id,
            batch_number: (string) $lot->batch->batch_number,
            quantity_received: (string) $lot->quantity_received,
            quantity_damaged: (string) $lot->quantity_damaged,
            quantity_written_off: (string) $lot->quantity_written_off,
            quantity_returned: (string) $lot->quantity_returned,
        );
    }
}
