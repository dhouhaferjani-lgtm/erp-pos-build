<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class WriteOffBatchRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('batches.write-off') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            // api.unmapped.007 (api.inventory): locations is company-scoped
            // (no tenant_id column); single-predicate ScopedExists::company.
            'location_id' => ['required', ScopedExists::company('locations', $companyId)],
            'reason' => ['required', 'in:expiry,damage,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Quantity is required',
            'quantity.gt' => 'Quantity must be greater than zero',
            'location_id.required' => 'Location is required',
            'reason.required' => 'Write-off reason is required',
            'reason.in' => 'Reason must be one of: expiry, damage, other',
        ];
    }
}
