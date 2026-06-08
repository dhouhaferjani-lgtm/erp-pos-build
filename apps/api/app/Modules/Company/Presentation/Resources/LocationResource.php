<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Resources;

use App\Modules\Company\Domain\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
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
            'company_id' => $this->company_id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'phone' => $this->phone,
            'email' => $this->email,
            'address_street' => $this->address_street,
            'address_city' => $this->address_city,
            'address_postal_code' => $this->address_postal_code,
            'address_country' => $this->address_country,
            'tax_id' => $this->tax_id,
            'vat_number' => $this->vat_number,
            'legal_identifiers' => $this->legal_identifiers,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'pos_enabled' => $this->pos_enabled,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
