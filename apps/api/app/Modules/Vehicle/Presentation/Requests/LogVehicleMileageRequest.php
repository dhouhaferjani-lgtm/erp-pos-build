<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Presentation\Requests;

use App\Modules\Vehicle\Domain\Enums\MileageSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LogVehicleMileageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicles.log_mileage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mileage' => ['required', 'integer', 'min:0'],
            'recorded_at' => ['required', 'date'],
            'source' => ['required', Rule::enum(MileageSource::class)],
            'context_document_id' => ['nullable', 'uuid'],
            'context_work_order_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
