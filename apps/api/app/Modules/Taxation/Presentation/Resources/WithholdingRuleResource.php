<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WithholdingTaxRule
 */
class WithholdingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'company_id' => $this->company_id,
            'is_global' => $this->isGlobal(),

            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'display_name' => $this->getDisplayName(),

            // Conditions
            'transaction_type' => $this->transaction_type?->value,
            'transaction_type_label' => $this->transaction_type?->label(),
            'partner_tax_status' => $this->partner_tax_status?->value,
            'partner_tax_status_label' => $this->partner_tax_status?->label(),
            'min_amount' => $this->min_amount,

            // Rate
            'rate' => $this->rate,
            'rate_percentage' => $this->getRateAsPercentage(),

            // Validity
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
            'is_effective_now' => $this->isEffectiveOn(now()),

            // Metadata
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
