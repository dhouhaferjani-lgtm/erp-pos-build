<?php

declare(strict_types=1);

namespace App\Modules\Uom\Presentation\Requests;

use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUnitRequest extends FormRequest
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
            'category_id' => 'required|uuid|exists:unit_categories,id',
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'symbol' => 'required|string|max:10',
            'conversion_factor' => 'required|numeric|min:0.0000000001',
            'decimal_places' => 'sometimes|integer|min:0|max:10',
            'rounding_method' => ['sometimes', 'string', Rule::enum(RoundingMethod::class)],
        ];
    }
}
