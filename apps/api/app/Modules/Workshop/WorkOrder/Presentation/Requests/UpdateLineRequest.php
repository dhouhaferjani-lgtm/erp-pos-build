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
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'labor_hours_actual' => ['nullable', 'numeric', 'min:0'],
            'assigned_technician_profile_id' => ['nullable', 'uuid'],
            'is_completed' => ['nullable', 'boolean'],
        ];
    }
}
