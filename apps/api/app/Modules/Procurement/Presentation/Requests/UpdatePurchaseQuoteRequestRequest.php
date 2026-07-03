<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePurchaseQuoteRequestRequest extends FormRequest
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
            'payload.rfq.group_id' => ['prohibited'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'uuid'],
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
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.rfq.group_id.prohibited' => 'RFQ group_id cannot be changed.',
            'lines.*.quantity.regex' => 'Quantity may have at most 4 decimal places.',
            'lines.*.unit_price.regex' => 'Unit price may have at most 3 decimal places.',
        ];
    }
}
