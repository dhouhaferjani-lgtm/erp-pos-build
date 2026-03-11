<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\HeldOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeldOrder
 */
final class HeldOrderResource extends JsonResource
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
            'terminal_id' => $this->terminal_id,
            'shift_id' => $this->shift_id,
            'cashier_id' => $this->cashier_id,
            'label' => $this->label,
            'cart_snapshot' => $this->cart_snapshot,
            'status' => $this->status->value,
            'held_at' => $this->held_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'recalled_at' => $this->recalled_at?->toIso8601String(),
            'line_count' => $this->getLineCount(),
            'total' => $this->getTotal(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
