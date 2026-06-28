<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Resources;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Batch
 */
class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'batch_number' => $this->batch_number,
            'manufacturing_date' => $this->manufacturing_date?->toDateString(),
            'expiry_date' => $this->expiry_date->toDateString(),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'is_active' => $this->is_active,
            'is_expired' => $this->is_expired,
            'is_recalled' => $this->is_recalled,
            'recall_reason' => $this->recall_reason,
            'recalled_at' => $this->recalled_at?->toIso8601String(),
            'notes' => $this->notes,
            'expiry_status' => strtoupper($this->expiryStatus()->value),
            'can_be_sold' => $this->canBeSold(),
            'total_quantity' => $this->total_quantity ?? 0,
            'available_quantity' => $this->available_quantity ?? 0,
            'product' => $this->whenLoaded('product', function () {
                /** @var Product $product */
                $product = $this->product;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    // Unit precision → drives the write-off qty input step.
                    'quantity_decimals' => ($product->relationLoaded('unitOfMeasure') && $product->unitOfMeasure !== null)
                        ? $product->unitOfMeasure->decimal_places
                        : 4,
                ];
            }),
            'batch_stock' => $this->whenLoaded('batchStock', fn () => $this->batchStock->map(fn ($stock) => [
                'location_id' => $stock->location_id,
                'quantity' => $stock->quantity,
                'reserved_quantity' => $stock->reserved_quantity,
                'available_quantity' => $stock->available_quantity,
            ])
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
