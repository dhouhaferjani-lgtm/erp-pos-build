<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GetAgedReceivablesRequest
 *
 * Form request validator for aged receivables report queries.
 *
 * Validates query parameters for retrieving an aged receivables report:
 * - as_of_date: Optional snapshot date (defaults to today if not provided)
 */
class GetAgedReceivablesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'as_of_date' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'as_of_date.date' => 'The as-of date must be a valid date in format YYYY-MM-DD.',
            'as_of_date.before_or_equal' => 'The as-of date cannot be in the future.',
        ];
    }
}
