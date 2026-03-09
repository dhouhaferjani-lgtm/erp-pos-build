<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for recording a cash payout.
 */
final class RecordPayoutRequest extends FormRequest
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
            'shift_id' => ['required', 'string', 'uuid', 'exists:pos_shifts,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => ['required', 'string', 'max:255'],
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
            'shift_id.required' => 'Shift ID is required',
            'shift_id.exists' => 'Shift does not exist',
            'amount.required' => 'Payout amount is required',
            'amount.numeric' => 'Payout amount must be a valid number',
            'amount.min' => 'Payout amount must be greater than zero',
            'amount.regex' => 'Payout amount must have at most 3 decimal places',
            'reason.required' => 'Reason is required',
            'reason.max' => 'Reason cannot exceed 255 characters',
        ];
    }
}
