<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Storefront (public, unauthenticated) booking FormRequest.
 *
 * Validates the payload shape only — CAPTCHA verification + per-IP /
 * per-phone rate limits are enforced upstream as middleware so invalid
 * requests never reach `rules()`. Vehicle + contact fields are denormalized
 * because the public caller usually has not yet been resolved to an ERP
 * Partner / Vehicle row.
 */
final class BookAppointmentStorefrontRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — gate is enforced by VerifyCaptcha + throttle middleware.
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_ids.*.estimated_price.regex' => 'Each estimated price must have at most 3 decimal places.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'contact_name' => ['required', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'appointment_type' => ['required', new Enum(AppointmentType::class)],
            'wait_type' => ['nullable', new Enum(WaitType::class)],
            'scheduled_start' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],

            // Vehicle information — at least one of plate / description is required.
            'vehicle_info' => ['required', 'array'],
            'vehicle_info.plate' => ['nullable', 'string', 'max:30'],
            'vehicle_info.description' => ['nullable', 'string', 'max:200'],
            'vehicle_info.make_model' => ['nullable', 'string', 'max:200'],

            // Planned services — at least one required. `service_ref_type` is
            // 'service' | 'bundle' (Workshop/Services or Workshop/Bundles).
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*.service_ref_type' => ['required', 'string', 'in:service,bundle'],
            'service_ids.*.service_ref_id' => ['required', 'uuid'],
            'service_ids.*.display_name' => ['required', 'string', 'max:200'],
            'service_ids.*.estimated_duration_minutes' => ['required', 'integer', 'min:1'],
            'service_ids.*.estimated_price' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
            'service_ids.*.display_order' => ['nullable', 'integer', 'min:0'],

            'services_summary' => ['nullable', 'string', 'max:2000'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],

            // Phone alias for the phone-based daily-cap limiter. Mirrors
            // contact_phone so the limiter and the payload agree.
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $email = $this->input('contact_email');
            $phone = $this->input('contact_phone');
            $emailEmpty = ! is_string($email) || trim($email) === '';
            $phoneEmpty = ! is_string($phone) || trim($phone) === '';
            if ($emailEmpty && $phoneEmpty) {
                $v->errors()->add(
                    'contact_email',
                    'At least one of contact_email or contact_phone must be provided.',
                );
            }

            $vehicle = $this->input('vehicle_info');
            if (is_array($vehicle)) {
                $plate = $vehicle['plate'] ?? null;
                $description = $vehicle['description'] ?? null;
                $makeModel = $vehicle['make_model'] ?? null;
                $plateEmpty = ! is_string($plate) || trim($plate) === '';
                $descriptionEmpty = ! is_string($description) || trim($description) === '';
                $makeModelEmpty = ! is_string($makeModel) || trim($makeModel) === '';
                if ($plateEmpty && $descriptionEmpty && $makeModelEmpty) {
                    $v->errors()->add(
                        'vehicle_info',
                        'At least one of vehicle_info.plate, vehicle_info.description, or vehicle_info.make_model must be provided.',
                    );
                }
            }
        });
    }
}
