<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePurchaseQuoteRequestRequest extends FormRequest
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
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        return [
            'partner_ids' => ['required', 'array', 'min:1', 'max:10'],
            'partner_ids.*' => [
                'uuid',
                'distinct',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('products', $tenantId, $companyId),
            ],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => [
                'required',
                'numeric',
                'regex:/^-?\d+(\.\d{1,4})?$/',
            ],
            'lines.*.unit_price' => [
                'nullable',
                'numeric',
                'regex:/^-?\d+(\.\d{1,3})?$/',
            ],
            'validity_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Quantity may have at most 4 decimal places.',
            'lines.*.unit_price.regex' => 'Unit price may have at most 3 decimal places.',
        ];
    }
}
