<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for order transformation.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->relationLoaded('lines')) {
            $this->lines->loadMissing('product.unitOfMeasure');
        }

        return [
            'id' => $this->id,
            'terminal_id' => $this->terminal_id,
            'shift_id' => $this->shift_id,
            'table_id' => $this->table_id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'cashier_id' => $this->cashier_id,
            'cashier_name' => $this->cashier_name,
            'customer_name' => $this->customer_name,
            'customer_identifier' => $this->customer_identifier,
            'partner_id' => $this->partner_id,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'currency' => $this->currency,
            'consumption_mode' => $this->consumption_mode?->value,
            'notes' => $this->notes,
            'opened_at' => $this->opened_at->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'ready_at' => $this->ready_at?->toISOString(),
            'served_at' => $this->served_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'receipt_id' => $this->receipt_id,
            'table' => $this->when($this->relationLoaded('table') && $this->table !== null, function () {
                /** @var Table $table */
                $table = $this->table;

                return [
                    'id' => $table->id,
                    'table_number' => $table->table_number,
                    'label' => $table->label,
                    'floor_name' => $table->floor?->name,
                ];
            }),
            'lines' => OrderLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
