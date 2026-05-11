<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\TableShape;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTableRequest extends FormRequest
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
            // Round-2 Opus Finding 3 — pos_floors has both tenant_id and
            // company_id; bare exists let tenant-A reference tenant-B's
            // floor when creating a table.
            'floor_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('pos_floors', $company->tenant_id, $company->id),
            ],
            'table_number' => ['required', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:100'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'shape' => ['nullable', Rule::enum(TableShape::class)],
        ];
    }
}
