<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TemplateValidationReportResource extends JsonResource
{
    /** @return array<string, array<array-key, scalar|null>|scalar|null> */
    public function toArray(Request $request): array
    {
        return [
            'valid' => $this->resource['valid'],
            'scope' => $this->resource['scope'],
            'errors' => $this->resource['errors'],
        ];
    }
}
