<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\CashDrawerOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashDrawerOperation
 */
final class CashDrawerOperationResource extends JsonResource
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
            'shift_id' => $this->shift_id,
            'operation_type' => $this->operation_type,
            'amount' => $this->amount,
            'user_id' => $this->user_id,
            'reason' => $this->reason,
            'receipt_id' => $this->receipt_id,
            'approval_id' => $this->approval_id,
            'approval_fiscal_event_id' => $this->approval_fiscal_event_id,
            'approval_scope' => $this->approval_scope,
            'approval_supervisor_user_id' => $this->approval_supervisor_user_id,
            'approval_target_hash' => $this->approval_target_hash,
            'created_at' => $this->created_at->toIso8601String(),

            // Helper methods
            'is_addition' => $this->isAddition(),
            'is_removal' => $this->isRemoval(),
            'is_deposit' => $this->isDeposit(),
            'is_payout' => $this->isPayout(),
            'has_receipt' => $this->hasReceipt(),
            'signed_amount' => $this->getSignedAmount(),
            'description' => $this->getDescription(),

            // Relationships (when loaded)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            'receipt' => $this->whenLoaded('receipt'),
        ];
    }
}
