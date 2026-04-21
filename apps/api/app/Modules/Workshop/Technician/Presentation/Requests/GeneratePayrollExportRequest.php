<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GeneratePayrollExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop.payroll.generate') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'pay_period_start' => ['required', 'date'],
            'pay_period_end' => ['required', 'date', 'after_or_equal:pay_period_start'],
            'technician_ids' => ['nullable', 'array'],
            'technician_ids.*' => ['uuid'],
        ];
    }
}
