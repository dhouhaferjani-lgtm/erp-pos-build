<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * NEVER ADD quantity_written_off / quantity_returned / quantity_sent_snapshot /
 * in_movement_id / scrap_movement_id / return_movement_id HERE — the first two
 * are close-only (§5.0b) and the last four are internal (`K_receipt`, §9.6).
 */
#[TypeScript]
final class StockTransferReceiptLineData extends Data
{
    /**
     * @param  list<StockTransferReceiptLineLotData>  $lots
     */
    public function __construct(
        public string $id,
        public string $transfer_line_id,
        public string $product_id,
        public ?string $variant_id,
        public bool $is_lot_tracked,
        public string $quantity_received,
        public string $quantity_damaged,
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
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            discrepancy_reason: $line->discrepancy_reason,
            discrepancy_note: $line->discrepancy_note,
            lots: array_values($line->lots->map(StockTransferReceiptLineLotData::fromModel(...))->values()->all()),
        );
    }
}
