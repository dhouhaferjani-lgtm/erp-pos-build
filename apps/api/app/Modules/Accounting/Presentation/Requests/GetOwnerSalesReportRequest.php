<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use App\Modules\Accounting\Application\DTOs\Reports\DateRangeData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class GetOwnerSalesReportRequest extends FormRequest
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
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['uuid'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'location_id' => ['sometimes', 'uuid'],
            'granularity' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['revenue', 'quantity'])],
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
    public function companyIds(): ?array
    {
        return $this->stringList('company_ids');
    }

    /**
     * @return list<string>|null
     */
    public function locationIds(): ?array
    {
        $locationIds = $this->stringList('location_ids');
        $singleLocation = $this->validated('location_id');

        if (is_string($singleLocation) && $singleLocation !== '') {
            $locationIds ??= [];
            $locationIds[] = $singleLocation;
        }

        return $locationIds === null ? null : array_values(array_unique($locationIds));
    }

    public function granularity(): string
    {
        $granularity = $this->validated('granularity');

        return is_string($granularity) ? $granularity : 'day';
    }

    public function limit(): int
    {
        $limit = $this->validated('limit');

        return is_numeric($limit) ? (int) $limit : 20;
    }

    public function sortBy(): string
    {
        $sortBy = $this->validated('sort_by');

        return is_string($sortBy) ? $sortBy : 'revenue';
    }

    /**
     * @return list<string>|null
     */
    private function stringList(string $key): ?array
    {
        $value = $this->validated($key);

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
