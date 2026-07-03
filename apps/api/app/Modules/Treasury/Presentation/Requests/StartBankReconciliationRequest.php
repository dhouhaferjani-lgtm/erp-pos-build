<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class StartBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(CompanyContext $companyContext): array
    {
        $company = $companyContext->requireCompany();
        $companyId = (string) $company->id;
        $tenantId = (string) $company->tenant_id;

        return [
            'repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'statement_date' => ['required', 'date'],
            'opening_balance' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'statement_balance' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.regex' => 'Opening balance must have at most 3 decimal places.',
            'statement_balance.regex' => 'Statement balance must have at most 3 decimal places.',
        ];
    }
}
