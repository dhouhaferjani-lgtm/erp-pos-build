<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch appointment fields that don't require a formal transition.
 * Scheduling time / bay changes route through `reschedule` instead.
 * Gated by `scheduling.appointments.update`.
 */
final class UpdateAppointmentRequest extends FormRequest
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
            'primary_technician_profile_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:200'],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'vehicle_description' => ['nullable', 'string', 'max:200'],
            'services_summary' => ['nullable', 'string', 'max:2000'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'color_label' => ['nullable', 'string', 'max:16'],
        ];
    }
}
