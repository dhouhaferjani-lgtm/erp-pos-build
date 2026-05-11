<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWithholdingCertificateRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'direction' => ['required', Rule::enum(WithholdingDirection::class)],
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'document_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'payment_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payments', $tenantId, $companyId),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
            'manual_rate_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_reason' => ['required_with:manual_rate_percentage', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'override_reason.required_with' => 'Override reason is required when specifying manual rate',
        ];
    }
}
