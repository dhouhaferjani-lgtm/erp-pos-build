<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of a kind=close receipt. A close is authored by a holder of
 * `inventory.transfers.reconcile`, so `canSeeExpected` is unconditionally true
 * for its author and the sent snapshot is legitimately carried here.
 */
#[TypeScript]
final class TransferCloseLineData extends Data
{
    /**
     * @param  list<TransferCloseLineLotData>  $lots
     */
    public function __construct(
        public string $id,
        public string $transfer_line_id,
        public string $product_id,
        public ?string $variant_id,
        public bool $is_lot_tracked,
        public string $quantity_sent_snapshot,
        public string $quantity_received,
        public string $quantity_damaged,
        public string $quantity_written_off,
        public string $quantity_returned,
        public ?TransferDiscrepancyReason $discrepancy_reason,
        public ?string $discrepancy_note,
        public array $lots,
    ) {}

    public static function fromModel(StockTransferReceiptLine $line): self
    {
        return new self(
            id: (string) $line->id,
            transfer_line_id: (string) $line->transfer_line_id,
            product_id: (string) $line->product_id,
            variant_id: $line->variant_id === null ? null : (string) $line->variant_id,
            is_lot_tracked: (bool) $line->is_lot_tracked,
            quantity_sent_snapshot: (string) $line->quantity_sent_snapshot,
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            quantity_written_off: (string) $line->quantity_written_off,
            quantity_returned: (string) $line->quantity_returned,
            discrepancy_reason: $line->discrepancy_reason,
            discrepancy_note: $line->discrepancy_note,
            lots: array_values($line->lots->map(TransferCloseLineLotData::fromModel(...))->values()->all()),
        );
    }
}
