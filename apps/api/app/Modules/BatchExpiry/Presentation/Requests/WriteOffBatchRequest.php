<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WriteOffBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('batches.write-off') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'location_id' => ['required', 'exists:locations,id'],
            'reason' => ['required', 'in:expiry,damage,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Quantity is required',
            'quantity.gt' => 'Quantity must be greater than zero',
            'location_id.required' => 'Location is required',
            'reason.required' => 'Write-off reason is required',
            'reason.in' => 'Reason must be one of: expiry, damage, other',
        ];
    }
}
