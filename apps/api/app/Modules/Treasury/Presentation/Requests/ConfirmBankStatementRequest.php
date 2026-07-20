<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'opening_balance' => ['required', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'closing_balance' => ['required', 'numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/'],
            'acknowledge_empty' => ['sometimes', 'boolean'],
        ];
    }
}
