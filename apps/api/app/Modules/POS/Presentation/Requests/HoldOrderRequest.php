<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for holding (parking) a cart order.
 */
final class HoldOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'string', 'uuid'],
            'shift_id' => ['required', 'string', 'uuid'],
            'label' => ['nullable', 'string', 'max:255'],
            'cart_snapshot' => ['required', 'array'],
            'cart_snapshot.lines' => ['required', 'array', 'min:1'],
            'cart_snapshot.lines.*.product_id' => ['required', 'string'],
            'cart_snapshot.lines.*.product_name' => ['required', 'string'],
            'cart_snapshot.lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'cart_snapshot.lines.*.unit_price' => ['required', 'numeric'],
            'cart_snapshot.lines.*.tax_rate' => ['required', 'numeric'],
            'cart_snapshot.lines.*.discount_amount' => ['nullable', 'numeric'],
            'cart_snapshot.lines.*.modifiers' => ['nullable', 'array'],
            'cart_snapshot.lines.*.special_instructions' => ['nullable', 'string'],
            'cart_snapshot.customer' => ['nullable', 'array'],
            'cart_snapshot.consumption_mode' => ['nullable', 'string'],
            'cart_snapshot.notes' => ['nullable', 'string'],
            'cart_snapshot.discount' => ['nullable', 'array'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
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
            'terminal_id.required' => 'Terminal ID is required.',
            'terminal_id.uuid' => 'Terminal ID must be a valid UUID.',
            'shift_id.required' => 'Shift ID is required.',
            'shift_id.uuid' => 'Shift ID must be a valid UUID.',
            'cart_snapshot.required' => 'Cart snapshot is required.',
            'cart_snapshot.lines.required' => 'Cart must contain at least one line item.',
            'cart_snapshot.lines.min' => 'Cart must contain at least one line item.',
            'cart_snapshot.lines.*.product_id.required' => 'Each line must have a product ID.',
            'cart_snapshot.lines.*.product_name.required' => 'Each line must have a product name.',
            'cart_snapshot.lines.*.quantity.required' => 'Each line must have a quantity.',
            'cart_snapshot.lines.*.quantity.min' => 'Quantity must be greater than zero.',
            'cart_snapshot.lines.*.unit_price.required' => 'Each line must have a unit price.',
            'cart_snapshot.lines.*.tax_rate.required' => 'Each line must have a tax rate.',
            'expires_in_minutes.min' => 'Expiry time must be at least 1 minute.',
            'expires_in_minutes.max' => 'Expiry time cannot exceed 1440 minutes (24 hours).',
        ];
    }
}
