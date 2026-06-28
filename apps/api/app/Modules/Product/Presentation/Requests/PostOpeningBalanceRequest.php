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
     * Reject a zero or negative opening_qty — this re-entry endpoint only accepts
     * a strictly positive quantity (unlike the optional inline create fields).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $qty = $this->input('opening_qty');
            if ($qty !== null && $qty !== '' && is_numeric($qty) && bccomp((string) $qty, '0', 4) <= 0) {
                $validator->errors()->add('opening_qty', __('validation.opening_qty_positive'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_qty' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,4})?$/'],
            'opening_unit_cost' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_qty.regex' => __('validation.opening_qty_format'),
            'opening_unit_cost.regex' => __('validation.opening_cost_format'),
        ];
    }
}
