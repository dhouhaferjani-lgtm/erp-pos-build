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
            'price_adjustment' => ['sometimes', 'numeric'],
            'recipe_multiplier' => ['sometimes', 'numeric', 'min:0.0001'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
