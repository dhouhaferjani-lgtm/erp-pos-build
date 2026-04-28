<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch a location-scoped ScheduleConfig row. Gated by `scheduling.bays.manage`
 * (config is treated as infrastructure — same permission plane as bay
 * operating-hours management).
 */
final class UpdateScheduleConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scheduling.bays.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'time_slot_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'default_appointment_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'walk_in_buffer_hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'overbooking_threshold_percent' => ['nullable', 'integer', 'min:0', 'max:200'],
            'online_booking_enabled' => ['nullable', 'boolean'],
            'online_booking_advance_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'online_booking_min_notice_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'online_booking_auto_confirm' => ['nullable', 'boolean'],
            'reminder_sms_hours_before' => ['nullable', 'integer', 'min:1', 'max:168'],
            'reminder_email_hours_before' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }
}
