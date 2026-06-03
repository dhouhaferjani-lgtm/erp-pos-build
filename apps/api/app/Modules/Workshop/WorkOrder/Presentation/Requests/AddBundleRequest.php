<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddBundleRequest extends FormRequest
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
            'bundle_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'vehicle_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'Quantity must have at most 4 decimal places.',
        ];
    }
}
