<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class EnrollPartnerRequest extends FormRequest
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

        return [
            'phone' => ['required', 'string', 'max:20'],
            // program_id optional: when omitted the controller falls back to the
            // tenant's single active program. loyalty_programs is scoped by
            // tenant_id only (company_ids is a JSON membership array).
            'program_id' => ['nullable', 'uuid', ScopedExists::tenant('loyalty_programs', $tenantId)],
        ];
    }
}
