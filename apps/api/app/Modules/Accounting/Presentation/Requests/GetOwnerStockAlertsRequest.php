<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GetOwnerStockAlertsRequest extends FormRequest
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
            'company_id' => ['sometimes', 'uuid'],
            'location_ids' => ['sometimes', 'array'],
            'location_ids.*' => ['uuid'],
            'threshold_pct' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function companyIds(): ?array
    {
        $companyId = $this->validated('company_id');

        return is_string($companyId) ? [$companyId] : null;
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

    public function thresholdPct(): int
    {
        $thresholdPct = $this->validated('threshold_pct');

        return is_numeric($thresholdPct) ? (int) $thresholdPct : 100;
    }
}
