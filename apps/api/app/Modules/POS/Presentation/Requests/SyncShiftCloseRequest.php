<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for offline shift close sync.
 *
 * Accepts shift close data with the offline timestamp for when
 * the shift was actually closed on the terminal.
 */
final class SyncShiftCloseRequest extends FormRequest
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
            'actual_cash' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'closed_at' => ['required', 'date'],
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
            'actual_cash.required' => 'Actual cash count is required',
            'actual_cash.numeric' => 'Actual cash must be a valid number',
            'actual_cash.min' => 'Actual cash cannot be negative',
            'actual_cash.regex' => 'Actual cash must have at most 3 decimal places',
            'closed_at.required' => 'Offline closed_at timestamp is required',
            'closed_at.date' => 'closed_at must be a valid date/time',
        ];
    }
}
