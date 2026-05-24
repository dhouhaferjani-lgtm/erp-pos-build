<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Partner
 */
final class PosCustomerMirrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $updatedAt = $this->updated_at?->toISOString();
        $accountStatus = $this->account_status ?? CustomerAccountStatus::Active;
        $accountStatusValue = $accountStatus->value;
        $chargeAccountEnabled = (bool) $this->is_active && $accountStatusValue === CustomerAccountStatus::Active->value;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->vat_number,
            'customer_category' => $this->customer_category?->value,
            'receivable_balance' => $this->receivable_balance,
            'credit_balance' => $this->credit_balance,
            'credit_limit' => $this->credit_limit,
            'payment_terms_days' => $this->payment_terms_days,
            'account_status' => $accountStatusValue,
            'account_status_version' => $this->account_status_version ?? 1,
            'account_status_reason' => $this->account_status_reason,
            'charge_account_enabled' => $chargeAccountEnabled,
            'charge_policy_version' => 'phase4-v1',
            'balance_updated_at' => $this->balance_updated_at?->toISOString(),
            'is_active' => $this->is_active ? 1 : 0,
            'sync_version' => $updatedAt,
            'updated_at' => $updatedAt,
            'synced_at' => Carbon::now()->toISOString(),
        ];
    }
}
