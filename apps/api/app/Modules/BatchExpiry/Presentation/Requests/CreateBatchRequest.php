<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('batches.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'batch_number' => ['required', 'string', 'max:100'],
            'manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Product is required',
            'product_id.exists' => 'Selected product does not exist',
            'batch_number.required' => 'Batch number is required',
            'batch_number.max' => 'Batch number cannot exceed 100 characters',
            'expiry_date.required' => 'Expiry date is required',
            'expiry_date.after' => 'Expiry date must be in the future',
            'manufacturing_date.before_or_equal' => 'Manufacturing date cannot be in the future',
        ];
    }
}
