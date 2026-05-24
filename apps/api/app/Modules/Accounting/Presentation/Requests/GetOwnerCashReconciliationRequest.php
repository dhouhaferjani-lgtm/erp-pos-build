<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GetOwnerCashReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('from') || ! $this->filled('to')) {
                return;
            }

            $from = CarbonImmutable::parse((string) $this->input('from'));
            $to = CarbonImmutable::parse((string) $this->input('to'));

            if ($from->diffInDays($to) > 366) {
                $validator->errors()->add('to', 'Date range must not exceed 366 days.');
            }
        });
    }

    public function dateRange(): DateRangeData
    {
        return new DateRangeData(
            from: CarbonImmutable::parse((string) $this->validated('from')),
            to: CarbonImmutable::parse((string) $this->validated('to')),
        );
    }

    /**
     * @return list<string>|null
     */
    public function locationIds(): ?array
    {
        $value = $this->validated('location_ids');

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
