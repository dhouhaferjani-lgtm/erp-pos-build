<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculateWithholdingRequest extends FormRequest
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
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
        ];
    }
}
