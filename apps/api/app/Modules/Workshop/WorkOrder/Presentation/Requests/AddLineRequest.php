<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class AddLineRequest extends FormRequest
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
            'line_type' => ['required', new Enum(WorkOrderLineType::class)],
            'product_id' => ['nullable', 'uuid'],
            'service_id' => ['nullable', 'uuid'],
            'display_name' => ['required', 'string', 'max:300'],
            'sku_or_code' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit' => ['required', 'string', 'max:16'],
            'unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'labor_hours_estimated' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'assigned_technician_profile_id' => ['nullable', 'uuid'],
            'is_customer_supplied' => ['sometimes', 'boolean'],
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
            'labor_hours_estimated.regex' => 'Labor hours must have at most 2 decimal places.',
        ];
    }
}
