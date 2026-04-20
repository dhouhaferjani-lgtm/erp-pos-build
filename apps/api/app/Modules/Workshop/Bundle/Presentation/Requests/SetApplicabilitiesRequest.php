<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SetApplicabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop-bundles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applicabilities' => ['required', 'array'],
            'applicabilities.*.platform_vehicle_id' => ['nullable', 'uuid'],
            'applicabilities.*.vehicle_type' => ['nullable', new Enum(VehicleTypeRef::class)],
            'applicabilities.*.vehicle_display' => ['nullable', 'string', 'max:200'],
            'applicabilities.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'applicabilities.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ];
    }
}
