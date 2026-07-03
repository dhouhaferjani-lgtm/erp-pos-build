<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class GetCashMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'repository_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function fromDate(): ?CarbonImmutable
    {
        $from = $this->validated('from');

        return is_string($from) ? CarbonImmutable::parse($from) : null;
    }

    public function toDate(): ?CarbonImmutable
    {
        $to = $this->validated('to');

        return is_string($to) ? CarbonImmutable::parse($to) : null;
    }

    public function repositoryId(): ?string
    {
        $repositoryId = $this->validated('repository_id');

        return is_string($repositoryId) ? $repositoryId : null;
    }

    public function page(): int
    {
        $page = $this->validated('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }

    public function perPage(): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? min(200, max(1, (int) $perPage)) : 50;
    }
}
