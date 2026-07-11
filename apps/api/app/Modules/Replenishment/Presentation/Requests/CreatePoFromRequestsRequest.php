<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Exists;

final class CreatePoFromRequestsRequest extends FormRequest
{
    public function __construct(private readonly CompanyContext $companyContext)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string|Exists>> */
    public function rules(): array
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'supplier_id' => ['required', 'uuid', ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)],
            'destination_location_id' => ['required', 'uuid'],
            'existing_document_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.request_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
        ];
    }
}
