<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for creating a single product variant.
 *
 * Monetary overrides are decimal strings (precision rule) capped at 4 decimal
 * places. cost_override is advisory only — see ProductVariantData / spec §6.7.
 */
class CreateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.variants.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_code' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:255'],
            'name_suffix' => ['required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_override' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cost_override' => ['sometimes', 'nullable', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'attribute_values' => ['sometimes', 'array'],
            'attribute_values.*.attribute_id' => ['required_with:attribute_values', 'uuid'],
            'attribute_values.*.attribute_value_id' => ['required_with:attribute_values', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_override.regex' => 'Price override must have at most 4 decimal places.',
            'cost_override.regex' => 'Cost override must have at most 4 decimal places.',
        ];
    }
}
