<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CountryTemplateAssignment */
final class CountryTemplateAssignmentResource extends JsonResource
{
    /** @return array<string, array<array-key, object|scalar|null>|object|scalar|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'domain' => $this->domain->value,
            'template_id' => $this->template_id,
            'template' => $this->whenLoaded('template', fn (): array => (new TemplateSummaryResource($this->template))->resolve($request)),
        ];
    }
}
