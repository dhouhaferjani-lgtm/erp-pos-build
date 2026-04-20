<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Requests;

use App\Modules\Scheduling\Domain\Enums\BayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Patch an existing Bay. Every field is optional — only the ones provided
 * are updated. Gated by `scheduling.bays.manage`.
 */
final class UpdateBayRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:100'],
            'bay_type' => ['nullable', new Enum(BayType::class)],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'operating_hours' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
