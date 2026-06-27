<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PostOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Enforce the qty ↔ cost conditional: if a positive opening_qty is given,
     * opening_unit_cost is required; cost without qty is also rejected.
     * Mirrors the identical logic in CreateProductRequest (Task 6).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $qty = $this->input('opening_qty');
            $cost = $this->input('opening_unit_cost');
            $hasPositiveQty = $qty !== null && $qty !== '' && is_numeric($qty) && bccomp((string) $qty, '0', 4) > 0;

            if ($hasPositiveQty && ($cost === null || $cost === '')) {
                $validator->errors()->add('opening_unit_cost', __('validation.opening_cost_required_with_qty'));
            }

            if (! $hasPositiveQty && $cost !== null && $cost !== '') {
                $validator->errors()->add('opening_unit_cost', __('validation.opening_cost_without_qty'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_qty' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'opening_unit_cost' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_qty.regex' => 'Opening quantity must have at most 4 decimal places.',
            'opening_unit_cost.regex' => 'Opening unit cost must have at most 3 decimal places.',
        ];
    }
}
