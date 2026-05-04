<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
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
        $companyId = $this->companyContext->requireCompanyId();
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
            // tax_configurations is a country-scoped global reference table
            // (no tenant_id / company_id columns) — structurally protected
            // by country_code. Cross-tenant access requires assigning a
            // foreign tax configuration via tax_configurations.country_code,
            // which the company's own country_code rejects at the
            // billing-flow tier. Tracked under api.catalog manual stub.
            'default_tax_configuration_id' => ['sometimes', 'nullable', 'uuid', 'exists:tax_configurations,id'],
            'manual_cost' => ['nullable', 'numeric', 'min:0'],
            'stock_unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
