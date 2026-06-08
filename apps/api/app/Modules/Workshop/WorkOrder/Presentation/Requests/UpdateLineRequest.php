<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'labor_hours_actual' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'assigned_technician_profile_id' => ['nullable', 'uuid'],
            'is_completed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'Quantity must have at most 4 decimal places.',
            'unit_price.regex' => 'Unit price must have at most 3 decimal places.',
            'tax_rate.regex' => 'Tax rate must have at most 3 decimal places.',
            'discount_percent.regex' => 'Discount percent must have at most 2 decimal places.',
            'labor_hours_actual.regex' => 'Labor hours must have at most 2 decimal places.',
        ];
    }
}
