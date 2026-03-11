<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for order line transformation.
 *
 * @mixin \App\Modules\POS\Domain\OrderLine
 */
final class OrderLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'line_number' => $this->line_number,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'variant_name' => $this->variant_name,
            'barcode' => $this->barcode,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'modifiers' => $this->modifiers,
            'special_instructions' => $this->special_instructions,
            'status' => $this->status->value,
            'sent_at' => $this->sent_at?->toISOString(),
            'prepared_at' => $this->prepared_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
