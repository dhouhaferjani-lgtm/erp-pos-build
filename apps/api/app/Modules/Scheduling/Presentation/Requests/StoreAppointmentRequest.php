<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Staff endpoint to create an appointment (source=manual).
 * Gated by `scheduling.appointments.create`.
 */
final class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('scheduling.appointments.create') ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid'],
            'bay_id' => ['nullable', 'uuid'],
            'primary_technician_profile_id' => ['nullable', 'uuid'],
            'customer_partner_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:200'],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'vehicle_description' => ['nullable', 'string', 'max:200'],
            'appointment_type' => ['required', new Enum(AppointmentType::class)],
            'wait_type' => ['nullable', new Enum(WaitType::class)],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1'],
            'planned_services' => ['required', 'array', 'min:1'],
            'planned_services.*.service_ref_type' => ['required', 'string', 'in:service,bundle'],
            'planned_services.*.service_ref_id' => ['required', 'uuid'],
            'planned_services.*.display_name' => ['required', 'string', 'max:200'],
            'planned_services.*.estimated_duration_minutes' => ['required', 'integer', 'min:1'],
            // estimated_price is a normalize-on-write field: AppointmentAuthoringService
            // canonicalizes any precision to the company currency scale via
            // CurrencyScale::bcformatStrict (Phase 4.14). No ingress decimal ceiling — the
            // storefront/staff may enter arbitrary precision and the service truncates it.
            'planned_services.*.estimated_price' => ['nullable', 'numeric'],
            'planned_services.*.display_order' => ['nullable', 'integer', 'min:0'],
            'services_summary' => ['nullable', 'string', 'max:2000'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
