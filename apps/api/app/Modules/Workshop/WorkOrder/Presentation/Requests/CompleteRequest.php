<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.complete') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'completion_mileage' => ['nullable', 'integer', 'min:0'],
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }
}
