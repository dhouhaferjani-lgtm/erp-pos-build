<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for opening a shift.
 */
final class OpenShiftRequest extends FormRequest
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
            'terminal_id' => ['required', 'string', 'uuid', 'exists:pos_terminals,id'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
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
            'opening_cash.required' => 'Opening cash amount is required',
            'opening_cash.numeric' => 'Opening cash must be a valid number',
            'opening_cash.min' => 'Opening cash cannot be negative',
            'opening_cash.regex' => 'Opening cash must have at most 2 decimal places',
        ];
    }
}
