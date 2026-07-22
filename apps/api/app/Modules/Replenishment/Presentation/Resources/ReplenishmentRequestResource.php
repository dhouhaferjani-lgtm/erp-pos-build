<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Resources;

use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReplenishmentRequest */
final class ReplenishmentRequestResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'location_name' => $this->location_name,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'variant_id' => $this->variant_id,
            'variant_name' => $this->variant_name,
            'requested_qty' => $this->requested_qty,
            'suggested_qty' => $this->suggested_qty,
            'quantity_decimals' => ($this->product !== null
                && $this->product->relationLoaded('unitOfMeasure')
                && $this->product->unitOfMeasure !== null)
                ? $this->product->unitOfMeasure->decimal_places
                : 4,
            'note' => $this->note,
            'request_count' => $this->request_count,
            'status' => $this->status->value,
            'source_channel' => $this->source_channel->value,
            'first_requested_at' => $this->first_requested_at->toIso8601String(),
            'last_requested_at' => $this->last_requested_at->toIso8601String(),
            'sourcing_document_id' => $this->sourcing_document_id,
            'fulfillment_type' => $this->fulfillment_type?->value,
            'fulfillment_id' => $this->fulfillment_id,
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
