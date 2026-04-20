<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AddComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop-bundles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'component_type' => ['required', new Enum(BundleComponentType::class)],
            'component_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'uuid'],
            'override_unit_price' => ['nullable', 'numeric', 'min:0'],
            'is_optional' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
