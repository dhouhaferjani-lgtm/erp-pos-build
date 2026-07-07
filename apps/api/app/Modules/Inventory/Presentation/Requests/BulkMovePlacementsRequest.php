<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkMovePlacementsRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'node_id' => [
                'required',
                'bail',
                'uuid',
                Rule::exists('location_nodes', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $company->tenant_id)->whereNull('deleted_at')),
            ],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['bail', 'uuid', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
        ];
    }
}
