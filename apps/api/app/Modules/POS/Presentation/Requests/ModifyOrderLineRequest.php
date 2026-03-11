<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for modifying an order line.
 *
 * All fields are optional — only provided fields are updated.
 */
final class ModifyOrderLineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware and Gate
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'modifiers' => ['sometimes', 'nullable', 'array'],
            'special_instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'Quantity must be greater than zero',
        ];
    }
}
