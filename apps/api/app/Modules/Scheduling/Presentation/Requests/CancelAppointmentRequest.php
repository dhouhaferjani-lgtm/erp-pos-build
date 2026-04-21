<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel an appointment. Gated by `scheduling.appointments.cancel`.
 */
final class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scheduling.appointments.cancel') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
