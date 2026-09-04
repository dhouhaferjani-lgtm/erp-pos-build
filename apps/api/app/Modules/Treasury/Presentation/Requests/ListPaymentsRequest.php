<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<object|string>> */
    public function rules(): array
    {
        return [
            'partner_id' => ['sometimes', 'nullable', 'uuid'],
            // `nullable` on both filters: the web list page sends `status=` /
            // `search=` when the operator clears a filter, and the global
            // ConvertEmptyStringsToNull middleware turns those into null.
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(PaymentStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
