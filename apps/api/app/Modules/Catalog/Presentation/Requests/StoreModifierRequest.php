<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreModifierRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('modifier-groups.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment' => ['sometimes', 'numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'component_type' => ['nullable', new Enum(ComponentType::class)],
            'component_id' => ['nullable', 'uuid', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'component_quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            // api.catalog.022 round-2: units has nullable tenant_id (system rows = NULL).
            'component_unit_id' => ['nullable', 'uuid', ScopedExists::tenantOrSystem('units', $company->tenant_id)],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_adjustment.regex' => 'Price adjustment must have at most 4 decimal places.',
            'component_quantity.regex' => 'Component quantity must have at most 4 decimal places.',
        ];
    }
}
