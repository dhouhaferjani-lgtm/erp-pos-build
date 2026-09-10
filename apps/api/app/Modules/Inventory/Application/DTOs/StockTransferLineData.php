<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Inventory\Domain\StockTransferLineBatchAllocation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the FULL transfer projection (§5.3 TransferPayloadBuilder).
 *
 * This shape is served only to an actor for whom canSeeExpected() is true: it
 * carries the sent quantity, the unit cost snapshot and the allocated freight.
 * The receiver projection is a DIFFERENT class, TransferReceiverLineData (§8.6b),
 * which declares none of those members.
 */
#[TypeScript]
final class StockTransferLineData extends Data
{
    /**
     * @param  numeric-string  $quantity  the sent quantity
     * @param  numeric-string|null  $unit_cost_snapshot
     * @param  numeric-string  $allocated_transfer_cost
     * @param  numeric-string  $quantity_received
     * @param  numeric-string  $quantity_damaged
     * @param  numeric-string  $quantity_written_off
     * @param  numeric-string  $quantity_returned
     * @param  numeric-string  $quantity_remaining
     * @param  list<StockTransferLineBatchAllocationData>  $batch_allocations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $product_id,
        public readonly ?string $product_name,
        public readonly ?string $product_sku,
        public readonly ?string $variant_id,
        public readonly ?string $variant_sku,
        public readonly ?string $variant_name,
        public readonly string $quantity,
        public readonly int $quantity_decimals,
        public readonly ?string $unit_cost_snapshot,
        public readonly string $allocated_transfer_cost,
        public readonly string $quantity_received,
        public readonly string $quantity_damaged,
        public readonly string $quantity_written_off,
        public readonly string $quantity_returned,
        public readonly string $quantity_remaining,
        public readonly array $batch_allocations,
    ) {}

    public static function fromModel(StockTransferLine $line): self
    {
        $product = $line->relationLoaded('product') ? $line->product : null;

        return new self(
            id: (string) $line->id,
            product_id: (string) $line->product_id,
            product_name: $product?->name,
            product_sku: $product?->sku,
            variant_id: $line->variant_id,
            variant_sku: $line->variant->sku ?? null,
            variant_name: $line->variant->name_suffix ?? null,
            quantity: (string) $line->quantity,
            quantity_decimals: $product?->unitOfMeasure->decimal_places ?? 4,
            unit_cost_snapshot: $line->unit_cost_snapshot === null ? null : (string) $line->unit_cost_snapshot,
            allocated_transfer_cost: (string) $line->allocated_transfer_cost,
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            quantity_written_off: (string) $line->quantity_written_off,
            quantity_returned: (string) $line->quantity_returned,
            quantity_remaining: $line->remainingQuantity(),
            batch_allocations: array_values($line->batchAllocations
                ->map(static fn (StockTransferLineBatchAllocation $a): StockTransferLineBatchAllocationData => StockTransferLineBatchAllocationData::fromModel($a))
                ->values()->all()),
        );
    }
}
