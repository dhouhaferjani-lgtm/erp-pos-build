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
            'allocations.*.amount' => ['required', 'string', 'regex:/^\d+(?:\.\d+)?$/'],
        ];
    }
}
