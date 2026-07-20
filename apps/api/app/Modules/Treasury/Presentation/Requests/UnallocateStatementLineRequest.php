<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class UnallocateStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('repositoryMovement') !== null) {
            $this->merge(['repository_movement_id' => $this->route('repositoryMovement')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(CompanyContext $companyContext): array
    {
        $company = $companyContext->requireCompany();

        return [
            'repository_movement_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany(
                    'repository_movements',
                    $company->tenant_id,
                    $company->id,
                ),
            ],
        ];
    }
}
