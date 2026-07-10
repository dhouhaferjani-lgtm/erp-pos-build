<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class CaptureReplenishmentRequest extends FormRequest
{
    public function __construct(private readonly CompanyContext $companyContext)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'location_id' => ['required', 'uuid'],
            'product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
            'variant_id' => ['nullable', 'uuid'],
            'requested_qty' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
