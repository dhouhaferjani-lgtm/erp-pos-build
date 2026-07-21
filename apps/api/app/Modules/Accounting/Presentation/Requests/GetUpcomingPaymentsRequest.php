<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GetUpcomingPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['uuid'],
            'group_by' => ['nullable', 'in:location'],
        ];
    }

    public function days(): int
    {
        return (int) ($this->validated('days') ?? 30);
    }

    /** @return list<string> */
    public function locationIds(): array
    {
        return array_values(array_filter((array) ($this->validated('location_ids') ?? []), 'is_string'));
    }
}
