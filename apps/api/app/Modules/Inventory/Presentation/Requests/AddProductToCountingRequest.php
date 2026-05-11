<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for adding a product to a draft counting operation (barcode scan).
 *
 * Used when manager scans a barcode to incrementally build the product list.
 */
class AddProductToCountingRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true; // Authorization checked in controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'barcode' => ['required_without:product_id', 'string', 'max:255'],
            'product_id' => ['required_without:barcode', 'string', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'location_id' => ['nullable', 'string', ScopedExists::company('locations', $company->id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required_without' => 'Either barcode or product_id is required.',
            'product_id.required_without' => 'Either barcode or product_id is required.',
            'product_id.exists' => 'The specified product does not exist.',
            'location_id.exists' => 'The specified location does not exist.',
        ];
    }
}
