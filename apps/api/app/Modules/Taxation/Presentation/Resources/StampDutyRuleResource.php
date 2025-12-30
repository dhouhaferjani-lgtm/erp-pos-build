<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Entities\StampDutyRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StampDutyRule
 */
class StampDutyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'document_type' => $this->document_type->value,
            'fiscal_category' => $this->fiscal_category?->value,
            'stamp_amount' => $this->stamp_amount,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from->format('Y-m-d'),
            'effective_to' => $this->effective_to?->format('Y-m-d'),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
