<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

class EnrollMemberRequest extends FormRequest
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
            'program_id' => [
                'required',
                'uuid',
                // loyalty_programs has tenant_id only (company_ids is a JSON
                // membership array, not a scoping column). ScopedExists::tenant
                // is the correct scope helper.
                ScopedExists::tenant('loyalty_programs', $tenantId),
            ],
            'welcome_bonus' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'welcome_bonus.regex' => 'Welcome bonus must not exceed 2 decimal places.',
        ];
    }
}
