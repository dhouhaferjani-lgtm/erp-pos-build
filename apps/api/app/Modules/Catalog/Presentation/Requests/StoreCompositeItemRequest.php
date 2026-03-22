<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Requests;

use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCompositeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('composite-items.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CompanyContext::class)->getCompanyId();

        return [
            'code' => [
                'required', 'string', 'max:100',
                Rule::unique('composite_items', 'code')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'vertical_type' => ['sometimes', new Enum(VerticalType::class)],
            'base_price' => ['required', 'numeric', 'min:0'],
            'production_type' => ['sometimes', new Enum(ProductionType::class)],
            'pricing_mode' => ['sometimes', new Enum(PricingMode::class)],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_configuration_id' => ['nullable', 'uuid', 'exists:tax_configurations,id'],
            'manual_cost' => ['nullable', 'numeric', 'min:0'],
            'stock_unit_id' => ['nullable', 'uuid', 'exists:units,id'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
