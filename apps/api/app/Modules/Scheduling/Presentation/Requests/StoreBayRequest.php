<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use App\Modules\Scheduling\Domain\Enums\BayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Create a new Bay. Gated by `scheduling.bays.manage`.
 */
final class StoreBayRequest extends FormRequest
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
            'location_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:100'],
            'bay_type' => ['required', new Enum(BayType::class)],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'operating_hours' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
