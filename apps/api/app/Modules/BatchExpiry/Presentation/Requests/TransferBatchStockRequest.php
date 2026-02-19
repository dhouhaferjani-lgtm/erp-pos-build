<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferBatchStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('batches.update') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from_location_id' => ['required', 'exists:locations,id'],
            'to_location_id' => ['required', 'exists:locations,id', 'different:from_location_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_location_id.required' => 'Source location is required',
            'to_location_id.required' => 'Destination location is required',
            'to_location_id.different' => 'Destination must be different from source location',
            'quantity.gt' => 'Quantity must be greater than zero',
        ];
    }
}
