<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\TableShape;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTableRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            // Round-2 Opus Finding 3 — same fix as CreateTableRequest.
            'floor_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('pos_floors', $company->tenant_id, $company->id),
            ],
            'table_number' => ['sometimes', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:100'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'shape' => ['nullable', Rule::enum(TableShape::class)],
        ];
    }
}
