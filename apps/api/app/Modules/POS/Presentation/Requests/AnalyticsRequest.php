<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AnalyticsRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'granularity' => ['sometimes', Rule::in(['hour', 'day', 'week', 'month'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'Start date is required.',
            'to.required' => 'End date is required.',
            'to.after_or_equal' => 'End date must be on or after the start date.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('from') && $this->filled('to')) {
                $from = CarbonImmutable::parse($this->input('from'));
                $to = CarbonImmutable::parse($this->input('to'));

                if ($from->diffInDays($to) > 90) {
                    $validator->errors()->add('to', 'Date range must not exceed 90 days.');
                }
            }
        });
    }
}
