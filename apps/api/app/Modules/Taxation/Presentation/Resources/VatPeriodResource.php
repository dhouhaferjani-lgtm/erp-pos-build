<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VatPeriod
 */
class VatPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'country_code' => $this->country_code,
            'period_type' => $this->period_type->value,
            'period_type_label' => $this->period_type->label(),
            'label' => $this->label,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_output_vat' => $this->total_output_vat,
            'total_input_vat' => $this->total_input_vat,
            'net_vat' => $this->net_vat,
            'credit_brought_forward' => $this->credit_brought_forward,
            'credit_carried_forward' => $this->credit_carried_forward,
            'amount_payable' => $this->amount_payable,
            'special_items' => $this->special_items,
            'declaration_data' => $this->declaration_data,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closed_by,
            'filed_at' => $this->filed_at?->toIso8601String(),
            'filed_by' => $this->filed_by,
            'filing_reference' => $this->filing_reference,
            'notes' => $this->notes,
            'breakdowns' => $this->whenLoaded('breakdowns'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
