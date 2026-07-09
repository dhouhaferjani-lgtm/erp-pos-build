<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for POST /payment-repositories/{id}/adjustments — a gated
 * manual cash-count-variance / correction adjustment (Treasury spine Task
 * 23). Authorization is enforced by the `can:treasury.adjust` route
 * middleware, not here.
 */
final class AdjustRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(MovementDirection::class)],
            // Money — regex ceiling per rule 19 (3 decimal places).
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason_code' => ['required', Rule::enum(MovementReasonCode::class)],
            'reason_text' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'direction' => 'direction',
            'amount' => 'amount',
            'reason_code' => 'reason code',
            'reason_text' => 'reason text',
        ];
    }
}
