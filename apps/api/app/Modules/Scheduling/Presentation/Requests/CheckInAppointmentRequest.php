<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Check-in an appointment (Confirmed|Scheduled → CheckedIn).
 * Gated by `scheduling.appointments.update`.
 */
final class CheckInAppointmentRequest extends FormRequest
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
            'actual_arrival_at' => ['nullable', 'date'],
        ];
    }
}
