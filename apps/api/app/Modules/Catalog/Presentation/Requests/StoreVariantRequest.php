<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\PriceAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('composite-items.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment_type' => ['sometimes', new Enum(PriceAdjustmentType::class)],
            'price_adjustment' => ['sometimes', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'recipe_multiplier' => ['sometimes', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_adjustment.regex' => 'Price adjustment must have at most 4 decimal places.',
            'recipe_multiplier.regex' => 'Recipe multiplier must have at most 4 decimal places.',
        ];
    }
}
