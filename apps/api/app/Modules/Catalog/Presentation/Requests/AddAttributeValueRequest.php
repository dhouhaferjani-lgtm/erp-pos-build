<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // Route param is {attributeId}. The DB enforces unique(attribute_id, code)
        // (per-tenant DB, so tenant scope is implicit at the connection level).
        // Validating here turns a duplicate into a readable 422 instead of a DB
        // unique-violation 500 (DEV-QA-045).
        $attributeId = $this->route('attributeId');

        return [
            'code' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_attribute_values', 'code')
                    ->where('attribute_id', is_string($attributeId) ? $attributeId : null),
            ],
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
            'code.unique' => 'This value code already exists for this attribute.',
        ];
    }
}
