<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Record Sales Withholding Request
 *
 * Validates request to record withholding applied by customer on sales document.
 */
class RecordSalesWithholdingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:partners,id'],
            'invoice_amount' => ['required', 'numeric', 'min:0'],
            'withholding_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'withholding_amount' => ['required', 'numeric', 'min:0'],
            'expected_receivable' => ['required', 'numeric', 'min:0'],
            'payment_id' => ['nullable', 'uuid', 'exists:payments,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Customer is required',
            'customer_id.exists' => 'Selected customer does not exist',
            'invoice_amount.required' => 'Invoice amount is required',
            'invoice_amount.numeric' => 'Invoice amount must be a number',
            'withholding_rate.required' => 'Withholding rate is required',
            'withholding_rate.min' => 'Withholding rate cannot be negative',
            'withholding_rate.max' => 'Withholding rate cannot exceed 100%',
            'withholding_amount.required' => 'Withholding amount is required',
            'expected_receivable.required' => 'Expected receivable amount is required',
        ];
    }
}
