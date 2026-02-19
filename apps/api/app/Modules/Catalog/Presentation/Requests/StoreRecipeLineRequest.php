<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreRecipeLineRequest extends FormRequest
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
            'component_type' => ['sometimes', new Enum(ComponentType::class)],
            'component_id' => ['required', 'uuid', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_optional' => ['sometimes', 'boolean'],
            'is_scalable' => ['sometimes', 'boolean'],
            'wastage_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
