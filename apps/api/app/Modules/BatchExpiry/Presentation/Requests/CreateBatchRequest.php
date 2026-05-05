<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class CreateBatchRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('batches.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            // api.unmapped.006 (api.inventory): products carries tenant_id +
            // company_id; scope the FK validator by both.
            'product_id' => ['required', ScopedExists::tenantAndCompany('products', $tenantId, $companyId)],
            'batch_number' => ['required', 'string', 'max:100'],
            'manufacturing_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['required', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Product is required',
            'product_id.exists' => 'Selected product does not exist',
            'batch_number.required' => 'Batch number is required',
            'batch_number.max' => 'Batch number cannot exceed 100 characters',
            'expiry_date.required' => 'Expiry date is required',
            'expiry_date.after' => 'Expiry date must be in the future',
            'manufacturing_date.before_or_equal' => 'Manufacturing date cannot be in the future',
        ];
    }
}
