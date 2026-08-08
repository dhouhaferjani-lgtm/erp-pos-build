<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockAdjustmentLine;
use App\Modules\Product\Domain\Product;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One correction line as the frontend consumes it (DPA V7 / T7).
 *
 * Flat public STRING props, the GoodsReceiptData shape — every quantity is a
 * decimal string, never a float (rule 19). The lot is exposed by its PUBLIC uuid,
 * matching the HTTP contract; the int `batch_id` never leaves the server.
 *
 * `quantity_decimals` is the product UNIT's precision, so the FE formats with
 * `formatQuantity(value, quantity_decimals)` instead of a literal scale — the
 * literal is exactly what `no-literal-decimal-places` and the quantity-display
 * ratchet exist to forbid.
 */
#[TypeScript]
final class StockAdjustmentLineData extends Data
{
    public function __construct(
        public string $id,
        public string $adjustment_id,
        public string $product_id,
        public ?string $product_name,
        public ?string $product_sku,
        public ?string $variant_id,
        public ?string $batch_uuid,
        public ?string $batch_number,
        public string $reason_code,
        public string $delta_quantity,
        public string $observed_before,
        public ?string $quantity_before,
        public ?string $quantity_after,
        public ?string $movement_id,
        public ?string $line_note,
        public int $quantity_decimals,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(StockAdjustmentLine $line): self
    {
        $product = $line->relationLoaded('product') ? $line->getRelation('product') : null;
        $batch = $line->relationLoaded('batch') ? $line->getRelation('batch') : null;

        return new self(
            id: $line->id,
            adjustment_id: $line->adjustment_id,
            product_id: $line->product_id,
            product_name: $product instanceof Product ? $product->name : null,
            product_sku: $product instanceof Product ? $product->sku : null,
            variant_id: $line->variant_id,
            batch_uuid: $batch !== null ? (string) $batch->uuid : null,
            batch_number: $batch !== null ? (string) $batch->batch_number : null,
            reason_code: $line->reason_code->value,
            delta_quantity: (string) $line->delta_quantity,
            observed_before: (string) $line->observed_before,
            quantity_before: $line->quantity_before !== null ? (string) $line->quantity_before : null,
            quantity_after: $line->quantity_after !== null ? (string) $line->quantity_after : null,
            movement_id: $line->movement_id,
            line_note: $line->line_note,
            quantity_decimals: self::resolveQuantityDecimals($product),
            created_at: $line->created_at?->toIso8601String() ?? '',
            updated_at: $line->updated_at?->toIso8601String() ?? '',
        );
    }

    /**
     * Falls back to the canonical storage scale when the product.unitOfMeasure
     * chain is not eager-loaded — the StockLevelData::resolveQuantityDecimals
     * convention.
     */
    private static function resolveQuantityDecimals(mixed $product): int
    {
        if (! $product instanceof Product
            || ! $product->relationLoaded('unitOfMeasure')
            || $product->unitOfMeasure === null) {
            return 4;
        }

        return $product->unitOfMeasure->decimal_places;
    }
}
