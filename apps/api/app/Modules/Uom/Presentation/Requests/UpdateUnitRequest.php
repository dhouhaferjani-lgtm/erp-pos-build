<?php

declare(strict_types=1);

namespace App\Modules\Uom\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => 'sometimes|string|max:20',
            'name' => 'sometimes|string|max:100',
            'symbol' => 'sometimes|string|max:10',
            'conversion_factor' => 'sometimes|numeric|min:0.0000000001',
            'decimal_places' => 'sometimes|integer|min:0|max:10',
            'rounding_method' => 'sometimes|string|in:half_up,floor,ceil',
        ];
    }
}
