<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'active_from' => ['nullable', 'date_format:H:i'],
            'active_until' => ['nullable', 'date_format:H:i', 'after:active_from'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['integer', 'min:1', 'max:7'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
