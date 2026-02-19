<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for creating a POS receipt.
 *
 * Validates terminal, line items, and optional customer.
 */
final class StoreReceiptRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'uuid', 'exists:pos_terminals,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.discount_type'    => ['nullable', 'string', 'in:percentage,fixed'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'lines.*.discount_reason' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'notes' => ['nullable', 'string', 'max:500'],
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
            'terminal_id.required' => 'Terminal ID is required',
            'terminal_id.exists' => 'Terminal does not exist',
            'lines.required' => 'At least one line item is required',
            'lines.min' => 'At least one line item is required',
            'lines.*.product_id.required' => 'Product ID is required for each line',
            'lines.*.product_id.exists' => 'Product does not exist',
            'lines.*.quantity.required' => 'Quantity is required for each line',
            'lines.*.quantity.gt' => 'Quantity must be greater than zero',
            'lines.*.unit_price.required' => 'Unit price is required for each line',
            'lines.*.unit_price.gte' => 'Unit price must be zero or greater',
            'lines.*.discount_type.in' => 'Discount type must be "percentage" or "fixed"',
            'lines.*.discount_percent.lte' => 'Discount percentage cannot exceed 100%',
            'customer_id.exists' => 'Customer does not exist',
        ];
    }
}
