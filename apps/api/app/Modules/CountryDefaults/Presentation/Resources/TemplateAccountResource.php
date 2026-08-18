<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Application\DTOs\TemplateAccountData;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminTemplateAccount */
final class TemplateAccountResource extends JsonResource
{
    /** @return array<string, scalar|null> */
    public function toArray(Request $request): array
    {
        return TemplateAccountData::fromModel($this->resource)->toArray();
    }
}
