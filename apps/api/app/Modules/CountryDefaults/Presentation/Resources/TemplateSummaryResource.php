<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Application\DTOs\TemplateSummaryData;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminTemplate */
class TemplateSummaryResource extends JsonResource
{
    /** @return array<string, array<array-key, object|scalar|null>|object|scalar|null> */
    public function toArray(Request $request): array
    {
        return TemplateSummaryData::fromModel($this->resource)->toArray();
    }
}
