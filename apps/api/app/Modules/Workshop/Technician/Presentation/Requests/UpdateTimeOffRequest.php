<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop.technicians.manage_time_off') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['sometimes', new Enum(TimeOffReason::class)],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'is_full_day' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
