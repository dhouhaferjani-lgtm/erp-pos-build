<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop.technicians.manage_time_entries') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'work_order_id' => ['sometimes', 'nullable', 'uuid'],
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'date'],
            'entry_type' => ['sometimes', new Enum(TimeEntryType::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
