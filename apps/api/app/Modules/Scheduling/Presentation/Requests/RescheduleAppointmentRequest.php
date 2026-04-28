<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reschedule an appointment to a new window / bay.
 * Gated by `scheduling.appointments.update`.
 */
final class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scheduling.appointments.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'new_bay_id' => ['nullable', 'uuid'],
            'new_scheduled_start' => ['required', 'date'],
            'new_scheduled_end' => ['required', 'date', 'after:new_scheduled_start'],
        ];
    }
}
