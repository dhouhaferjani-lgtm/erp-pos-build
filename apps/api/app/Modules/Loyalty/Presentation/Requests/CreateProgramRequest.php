<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateProgramRequest extends FormRequest
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
        // Scanner-blind-spot fix bundled into the api.loyalty cluster:
        // company_ids is a JSON membership array on loyalty_programs that
        // points at companies.id. Pre-fix bare `exists:companies,id` would
        // accept any tenant's company UUID, letting tenant-A's program
        // target tenant-B's company. Companies has tenant_id only.
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'program_type' => ['required', new Enum(ProgramType::class)],
            'status' => ['nullable', new Enum(ProgramStatus::class)],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => [
                'required',
                'uuid',
                ScopedExists::tenant('companies', $tenantId),
            ],
            'currency' => ['nullable', 'string', 'size:3'], // ISO 4217 currency code
            'points_expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'terms_and_conditions' => ['nullable', 'string', 'max:10000'],
            'welcome_bonus_points' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
