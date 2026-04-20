<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use App\Modules\Workshop\WorkOrder\Domain\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class CancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.cancel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', new Enum(CancellationReason::class)],
            'note' => ['nullable', 'string'],
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }
}
