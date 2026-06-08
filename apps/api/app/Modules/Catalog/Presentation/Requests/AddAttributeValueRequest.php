<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddAttributeValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.attributes.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
            'hex_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hex_color.regex' => 'Hex color must be a 6-digit hex value (e.g. #1A2B3C).',
        ];
    }
}
