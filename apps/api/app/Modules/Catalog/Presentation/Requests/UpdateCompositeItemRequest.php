<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCompositeItemRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('composite-items.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $itemId = $this->route('id');

        return [
            'code' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('composite_items', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->ignore($itemId),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', ScopedExists::company('categories', $companyId)],
            'vertical_type' => ['sometimes', new Enum(VerticalType::class)],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'production_type' => ['sometimes', new Enum(ProductionType::class)],
            'pricing_mode' => ['sometimes', new Enum(PricingMode::class)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // api.catalog.023 round-2: tax_configurations is country-scoped (no tenant_id/
            // company_id). TaxCalculationService selects applicable configs by
            // company.country_code at calculation time (Modules/Taxation/Domain/Services/
            // TaxCalculationService.php:38-43), so a mismatched default_tax_configuration_id
            // is silently ignored — no cross-tenant data leak, but the field stores
            // integrity garbage. Defense-in-depth coherence check enforced below via
            // TaxConfigurationCountryCoherent rule.
            'default_tax_configuration_id' => [
                'sometimes', 'nullable', 'uuid', 'exists:tax_configurations,id',
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
            'manual_cost' => ['nullable', 'numeric', 'min:0'],
            // api.catalog.022 round-2: units has nullable tenant_id (system rows = NULL).
            'stock_unit_id' => ['nullable', 'uuid', ScopedExists::tenantOrSystem('units', $company->tenant_id)],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
