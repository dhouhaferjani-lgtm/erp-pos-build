<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Application\DTOs\TemplateData;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Http\Request;

/** @mixin AdminTemplate */
final class TemplateResource extends TemplateSummaryResource
{
    /** @return array<string, array<array-key, object|scalar|array<array-key, object|scalar|null>|null>|object|scalar|null> */
    public function toArray(Request $request): array
    {
        return TemplateData::fromModel($this->resource)->toArray();
    }
}
