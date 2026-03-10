<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('batches.update') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'batch_number' => ['sometimes', 'string', 'max:100'],
            'manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['sometimes', 'date', 'after:today'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'batch_number.max' => 'Batch number cannot exceed 100 characters',
            'expiry_date.after' => 'Expiry date must be in the future',
            'manufacturing_date.before_or_equal' => 'Manufacturing date cannot be in the future',
        ];
    }
}
