<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.operate_terminal') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'customer_id' => ['nullable', 'string', 'uuid'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['required_with:items', 'string', 'uuid'],
            'items.*.category_id' => ['nullable', 'string', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'items.*.line_total' => ['required_with:items', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subtotal.regex' => 'Subtotal must not exceed 3 decimal places.',
            'items.*.quantity.regex' => 'Item quantity must not exceed 4 decimal places.',
            'items.*.unit_price.regex' => 'Item unit price must not exceed 3 decimal places.',
            'items.*.line_total.regex' => 'Item line total must not exceed 3 decimal places.',
        ];
    }
}
