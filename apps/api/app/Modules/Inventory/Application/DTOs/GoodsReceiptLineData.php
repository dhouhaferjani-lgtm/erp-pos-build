<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\GoodsReceiptLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class GoodsReceiptLineData extends Data
{
    public function __construct(
        public string $id,
        public string $goods_receipt_id,
        public string $po_line_id,
        public string $product_id,
        public ?string $variant_id,
        public string $received_qty,
        public string $free_qty,
        public ?string $received_unit_price,
        public ?string $landed_unit_cost,
        public ?string $accrual_unit_cost,
        public ?string $effective_unit_cost,
        public ?string $movement_id,
        public ?string $free_movement_id,
        public string $quantity_invoiced,
        public string $free_quantity_invoiced,
        public ?string $price_override_by,
        public ?string $price_override_at,
        public ?string $price_override_old_basis,
        public ?string $price_override_reason,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(GoodsReceiptLine $line): self
    {
        return new self(
            id: $line->id,
            goods_receipt_id: $line->goods_receipt_id,
            po_line_id: $line->po_line_id,
            product_id: $line->product_id,
            variant_id: $line->variant_id,
            received_qty: (string) $line->received_qty,
            free_qty: (string) $line->free_qty,
            received_unit_price: $line->received_unit_price !== null ? (string) $line->received_unit_price : null,
            landed_unit_cost: $line->landed_unit_cost !== null ? (string) $line->landed_unit_cost : null,
            accrual_unit_cost: $line->accrual_unit_cost !== null ? (string) $line->accrual_unit_cost : null,
            effective_unit_cost: $line->effective_unit_cost !== null ? (string) $line->effective_unit_cost : null,
            movement_id: $line->movement_id,
            free_movement_id: $line->free_movement_id,
            quantity_invoiced: (string) $line->quantity_invoiced,
            free_quantity_invoiced: (string) $line->free_quantity_invoiced,
            price_override_by: $line->price_override_by,
            price_override_at: $line->price_override_at?->toIso8601String(),
            price_override_old_basis: $line->price_override_old_basis !== null ? (string) $line->price_override_old_basis : null,
            price_override_reason: $line->price_override_reason,
            created_at: $line->created_at?->toIso8601String() ?? '',
            updated_at: $line->updated_at?->toIso8601String() ?? '',
        );
    }
}
