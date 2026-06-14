<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
final class ShiftResource extends JsonResource
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
            'cashier_id' => $this->cashier_id,
            'shift_number' => $this->shift_number,
            // One-id model: a device-authored shift's fiscal session_id == its
            // id; exposed (with opened_at_device) so the device reconcile read
            // can round-trip a projected shift (Codex F-6/F-16).
            'session_id' => $this->id,
            'opened_at_device' => $this->opened_at->toIso8601String(),
            'opening_cash' => $this->opening_cash,
            'expected_cash' => $this->expected_cash,
            'actual_cash' => $this->actual_cash,
            'variance' => $this->variance,
            'status' => $this->status->value,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'duration_seconds' => $this->getDurationSeconds(),
            'formatted_duration' => $this->getFormattedDuration(),
            'has_variance' => $this->hasVariance(),
            'has_overage' => $this->hasOverage(),
            'has_shortage' => $this->hasShortage(),

            // Relationships (when loaded)
            'terminal' => $this->whenLoaded('terminal'),
            'cashier' => $this->whenLoaded('cashier', function () {
                return [
                    'id' => $this->cashier->id,
                    'name' => $this->cashier->name,
                    'email' => $this->cashier->email,
                ];
            }),
            'closed_by_user' => $this->whenLoaded('closedBy', function () {
                return $this->closedBy ? [
                    'id' => $this->closedBy->id,
                    'name' => $this->closedBy->name,
                    'email' => $this->closedBy->email,
                ] : null;
            }),
        ];
    }
}
