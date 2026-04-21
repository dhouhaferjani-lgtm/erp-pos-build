<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Presentation\Requests;

use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Partial update payload for an existing bundle component.
 *
 * All fields are optional (`sometimes`). When `component_type` is
 * present, a matching `component_id` is required. `notes` is nullable
 * by design — passing `null` explicitly clears it, while omitting the
 * key leaves the stored value untouched (handled controller-side via
 * `array_key_exists`).
 */
class PatchComponentRequest extends FormRequest
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
            'component_type' => ['sometimes', new Enum(BundleComponentType::class)],
            'component_id' => ['sometimes', 'required_with:component_type', 'uuid'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'unit_id' => ['sometimes', 'uuid'],
            'override_unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_optional' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
