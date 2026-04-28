<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\Table;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for table transformation.
 *
 * @mixin Table
 */
final class TableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'floor_id' => $this->floor_id,
            'table_number' => $this->table_number,
            'label' => $this->label,
            'seats' => $this->seats,
            'status' => $this->status->value,
            'shape' => $this->shape?->value,
            'position_x' => $this->position_x,
            'position_y' => $this->position_y,
            'width' => $this->width,
            'height' => $this->height,
            'current_order_id' => $this->current_order_id,
            'floor' => new FloorResource($this->whenLoaded('floor')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
