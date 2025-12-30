<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxConfiguration
 */
class TaxConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'tax_type' => $this->tax_type->value,
            'name' => $this->name,
            'code' => $this->code,
            'percentage_rate' => $this->percentage_rate,
            'fixed_amount' => $this->fixed_amount,
            'applies_to' => $this->applies_to->value,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
