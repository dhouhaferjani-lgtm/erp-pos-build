<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminTemplateAccount */
final class TemplateAccountResource extends JsonResource
{
    /** @return array<string, scalar|null> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'parent_code' => $this->parent_code,
            'system_purpose' => $this->system_purpose?->value,
            'is_system' => $this->is_system,
            'sort_order' => $this->sort_order,
        ];
    }
}
