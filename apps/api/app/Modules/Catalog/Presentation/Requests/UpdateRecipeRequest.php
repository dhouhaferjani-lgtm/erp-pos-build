<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('composite-items.manage-recipes') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version_name' => ['nullable', 'string', 'max:255'],
            'yield_quantity' => ['sometimes', 'numeric', 'min:0.0001'],
            'yield_unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'cook_time_minutes' => ['nullable', 'integer', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
