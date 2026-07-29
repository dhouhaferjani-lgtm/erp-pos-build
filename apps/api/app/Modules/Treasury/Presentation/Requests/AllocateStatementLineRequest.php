<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class AllocateStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(CompanyContext $companyContext): array
    {
        $company = $companyContext->requireCompany();

        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.repository_movement_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany(
                    'repository_movements',
                    $company->tenant_id,
                    $company->id,
                ),
            ],
            // Money — regex ceiling per CLAUDE.md rule 19 (3 decimal places =
            // currency scale floor). Excess precision is rejected here, not deep
            // in the allocation domain service.
            'allocations.*.amount' => ['required', 'string', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocations.*.amount.regex' => 'Amount must have at most 3 decimal places.',
        ];
    }
}
