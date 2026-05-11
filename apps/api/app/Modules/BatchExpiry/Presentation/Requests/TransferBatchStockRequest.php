<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class TransferBatchStockRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('batches.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            // api.unmapped.008 (api.inventory): locations is company-scoped
            // (no tenant_id column); single-predicate ScopedExists::company.
            'from_location_id' => ['required', ScopedExists::company('locations', $companyId)],
            // api.unmapped.009 (api.inventory): same scoping for to_location_id;
            // 'different' rule still pins from != to.
            'to_location_id' => ['required', ScopedExists::company('locations', $companyId), 'different:from_location_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_location_id.required' => 'Source location is required',
            'to_location_id.required' => 'Destination location is required',
            'to_location_id.different' => 'Destination must be different from source location',
            'quantity.gt' => 'Quantity must be greater than zero',
        ];
    }
}
