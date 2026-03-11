<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\POS\Domain\Enums\ConsumptionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for creating a POS order.
 */
final class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware and Gate
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
            'shift_id' => ['required', 'uuid', 'exists:pos_shifts,id'],
            'table_id' => ['nullable', 'uuid'],
            'partner_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'consumption_mode' => ['nullable', 'string', Rule::enum(ConsumptionMode::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'shift_id.required' => 'Shift ID is required',
            'shift_id.exists' => 'Shift does not exist',
            'partner_id.exists' => 'Customer does not exist',
        ];
    }
}
