<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for processing a POS receipt return.
 *
 * Validates terminal, return lines (with quantities), and return reason.
 */
final class StoreReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by Gate in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'terminal_id' => 'required|uuid|exists:pos_terminals,id',
            'return_reason' => ['required', 'string', Rule::in(array_column(ReturnReason::cases(), 'value'))],
            'lines' => 'required|array|min:1',
            'lines.*.line_id' => 'required|uuid',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'At least one line item is required for a return.',
            'lines.min' => 'At least one line item is required for a return.',
            'lines.*.line_id.required' => 'Each return line must reference an original receipt line.',
            'lines.*.quantity.required' => 'Each return line must have a quantity.',
            'lines.*.quantity.min' => 'Return quantity must be positive.',
            'return_reason.required' => 'A return reason is required.',
            'return_reason.in' => 'Invalid return reason.',
        ];
    }
}
