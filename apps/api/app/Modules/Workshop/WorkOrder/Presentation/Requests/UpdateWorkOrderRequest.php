<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Patches the WorkOrder header (diagnosis, notes, scheduling, primary tech).
 * Requires `work-orders.update`.
 */
final class UpdateWorkOrderRequest extends FormRequest
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
            'primary_technician_profile_id' => ['nullable', 'uuid'],
            'diagnosis' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date'],
            'promised_at' => ['nullable', 'date'],
        ];
    }
}
