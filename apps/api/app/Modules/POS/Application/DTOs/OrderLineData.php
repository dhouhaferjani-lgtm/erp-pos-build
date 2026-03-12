<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\OrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class OrderLineData extends Data
{
    /**
     * @param  array<string, mixed>|null  $modifiers
     */
    public function __construct(
        public string $id,
        public string $order_id,
        public int $line_number,
        public string $product_id,
        public string $product_name,
        public ?string $variant_name,
        public ?string $barcode,
        public string $quantity,
        public string $unit_price,
        public string $discount_amount,
        public string $tax_rate,
        public string $tax_amount,
        public string $line_total,
        public ?array $modifiers,
        public ?string $special_instructions,
        public OrderLineStatus $status,
        public ?string $sent_at,
        public ?string $prepared_at,
        public string $created_at,
    ) {}

    /**
     * Create from an OrderLine model.
     */
    public static function fromModel(OrderLine $line): self
    {
        return new self(
            id: $line->id,
            order_id: $line->order_id,
            line_number: $line->line_number,
            product_id: $line->product_id,
            product_name: $line->product_name,
            variant_name: $line->variant_name,
            barcode: $line->barcode,
            quantity: $line->quantity,
            unit_price: $line->unit_price,
            discount_amount: $line->discount_amount,
            tax_rate: $line->tax_rate,
            tax_amount: $line->tax_amount,
            line_total: $line->line_total,
            modifiers: $line->modifiers,
            special_instructions: $line->special_instructions,
            status: $line->status,
            sent_at: $line->sent_at?->toISOString(),
            prepared_at: $line->prepared_at?->toISOString(),
            created_at: $line->created_at->toIso8601String(),
        );
    }
}
