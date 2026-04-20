<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.transition') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_status' => ['required', new Enum(WorkOrderStatus::class)],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'context' => ['nullable', 'array'],
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }
}
