<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockTransferRequest extends FormRequest
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
            'source_location_id' => ['required', 'string', 'uuid', ScopedExists::company('locations', $company->id)],
            'destination_location_id' => ['required', 'string', 'uuid', 'different:source_location_id', ScopedExists::company('locations', $company->id)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'transfer_cost' => ['nullable', 'numeric', 'min:0'],
            'transfer_cost_label' => ['nullable', 'string', 'max:64'],
            'transfer_cost_distribution' => ['nullable', Rule::enum(TransferCostDistribution::class)],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'string', 'uuid', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.batch_allocations' => ['nullable', 'array'],
            'lines.*.batch_allocations.*.batch_id' => [
                'required',
                'integer',
                Rule::exists('product_batches', 'id')->where('tenant_id', $company->tenant_id)->where('company_id', $company->id),
            ],
            'lines.*.batch_allocations.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
