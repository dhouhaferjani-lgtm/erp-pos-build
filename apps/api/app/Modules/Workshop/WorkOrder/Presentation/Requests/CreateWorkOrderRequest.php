<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Presentation\Requests;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Creates a new WorkOrder at intake. Requires `work-orders.create`.
 */
final class CreateWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work-orders.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'uuid'],
            'type' => ['required', new Enum(WorkOrderType::class)],
            'customer_partner_id' => ['required', 'uuid'],
            'vehicle_id' => ['required', 'uuid'],
            'primary_technician_profile_id' => ['nullable', 'uuid'],
            'mileage_at_intake' => ['nullable', 'integer', 'min:0'],
            'customer_complaint' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date'],
            'promised_at' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }
}
