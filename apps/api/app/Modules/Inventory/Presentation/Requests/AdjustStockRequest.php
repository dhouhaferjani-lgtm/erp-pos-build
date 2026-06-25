<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Inventory\Domain\Enums\MovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces auth + can:inventory.adjust.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'uuid'],
            'location_id' => ['required', 'string', 'uuid'],
            'new_quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            // Typed reason from the restricted manual set (no document/POS reasons,
            // no CountCorrection — that is reserved for the counting flow).
            'reason_code' => ['required', Rule::in(MovementReason::manualAdjustmentValues())],
            // Optional free-text note; persisted as the movement reference.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_quantity.regex' => 'The new quantity must have at most 4 decimal places.',
        ];
    }
}
