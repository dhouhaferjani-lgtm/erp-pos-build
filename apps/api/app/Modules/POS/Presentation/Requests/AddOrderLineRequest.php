<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for adding a line to a POS order.
 */
final class AddOrderLineRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware and Gate
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            // api.pos-stabilization.005 — scope products by caller tenant + company.
            'product_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'unit_price' => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['required', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount_amount' => ['nullable', 'numeric', 'gte:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'modifiers' => ['nullable', 'array'],
            'special_instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Product ID is required',
            'product_id.exists' => 'Product does not exist',
            'quantity.required' => 'Quantity is required',
            'quantity.gt' => 'Quantity must be greater than zero',
            'quantity.regex' => 'Quantity must have at most 4 decimal places',
            'unit_price.required' => 'Unit price is required',
            'unit_price.gte' => 'Unit price must be zero or greater',
            'unit_price.regex' => 'Unit price must have at most 3 decimal places',
            'tax_rate.required' => 'Tax rate is required',
            'tax_rate.regex' => 'Tax rate must have at most 2 decimal places',
            'discount_amount.regex' => 'Discount amount must have at most 3 decimal places',
        ];
    }
}
