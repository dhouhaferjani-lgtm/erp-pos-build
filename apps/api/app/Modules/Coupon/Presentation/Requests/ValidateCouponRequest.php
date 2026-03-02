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
            'subtotal' => ['required', 'numeric', 'gt:0'],
            'customer_id' => ['nullable', 'string', 'uuid'],
            'items' => ['sometimes', 'array'],
            'items.*.product_id' => ['required_with:items', 'string', 'uuid'],
            'items.*.category_id' => ['nullable', 'string', 'uuid'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'gte:0'],
            'items.*.line_total' => ['required_with:items', 'numeric', 'gte:0'],
        ];
    }
}
