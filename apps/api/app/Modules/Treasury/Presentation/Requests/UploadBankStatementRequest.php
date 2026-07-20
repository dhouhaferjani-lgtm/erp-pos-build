<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class UploadBankStatementRequest extends FormRequest
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
            'payment_repository_id' => [
                'required', 'uuid', ScopedExists::tenant('payment_repositories', $company->tenant_id),
            ],
            'parser_profile_id' => [
                'required', 'uuid', ScopedExists::tenant('statement_import_profiles', $company->tenant_id),
            ],
            'file' => ['required', 'file', 'max:20480', 'extensions:csv,xlsx'],
        ];
    }
}
