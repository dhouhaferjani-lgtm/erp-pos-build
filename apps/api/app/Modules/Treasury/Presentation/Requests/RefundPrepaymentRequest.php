<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class RefundPrepaymentRequest extends FormRequest
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
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'payment_method_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ];
    }
}
