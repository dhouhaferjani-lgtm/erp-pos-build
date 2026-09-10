<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferReceiptLineLot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A lot row of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * NEVER ADD quantity_written_off / quantity_returned HERE: their ABSENCE on a
 * receipt echo is the §5.0b contract, and TransferBlindLeakOracleTest asserts it
 * structurally (`K_receipt`, §9.6). The close family carries them instead.
 */
#[TypeScript]
final class StockTransferReceiptLineLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public string $quantity_received,
        public string $quantity_damaged,
    ) {}

    public static function fromModel(StockTransferReceiptLineLot $lot): self
    {
        return new self(
            batch_id: (string) $lot->batch_id,
            batch_number: (string) $lot->batch->batch_number,
            quantity_received: (string) $lot->quantity_received,
            quantity_damaged: (string) $lot->quantity_damaged,
        );
    }
}
