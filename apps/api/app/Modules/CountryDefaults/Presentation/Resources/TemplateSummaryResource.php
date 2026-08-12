<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminTemplate */
class TemplateSummaryResource extends JsonResource
{
    /** @return array<string, array<array-key, object|scalar|null>|object|scalar|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain->value,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'content_hash' => $this->content_hash,
            'standard_ref' => $this->standard_ref,
            'certified_country_codes' => $this->certified_country_codes,
            'capability_registry_version' => $this->capability_registry_version,
            'certified_by' => $this->certified_by,
            'published_at' => $this->published_at?->toAtomString(),
            'cloned_from_id' => $this->cloned_from_id,
        ];
    }
}
