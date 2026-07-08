<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use App\Enums\Vertical;
use App\Modules\Catalog\Presentation\Rules\TaxConfigurationCountryCoherent;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\AutomotiveArticleStatus;
use App\Modules\Product\Domain\Enums\BrandQualityTier;
use App\Modules\Product\Domain\Enums\CrossReferenceType;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Product\Presentation\Requests\Concerns\ValidatesMarginBand;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesMarginBand;

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
     * Derive is_physical from type when is_physical is absent.
     * service → false; part/consumable → true.
     */
    public function prepareForValidation(): void
    {
        if ($this->has('type') && ! $this->has('is_physical')) {
            $typeValue = $this->input('type');
            $type = is_string($typeValue) ? ProductType::tryFrom($typeValue) : null;

            if ($type !== null) {
                $this->merge([
                    'is_physical' => $type !== ProductType::Service,
                ]);
            }
        }
    }

    /**
     * Reject vertical-specific metadata the tenant's vertical does not permit —
     * including empty/null payloads that a bare `prohibited` rule would let
     * through (Laravel treats `prohibited` as "not required", so null and `[]`
     * pass). Parapharmacy metadata is allowed only for the Parapharmacy
     * vertical; automotive metadata only for automotive verticals (there is no
     * single "Automotive" module, so the vertical is the authority).
     *
     * Also rejects contradicting type ↔ is_physical combinations:
     * service + is_physical=true → 422 on is_physical
     * part/consumable + is_physical=false → 422 on is_physical
     */
    public function withValidator(Validator $validator): void
    {
        $vertical = $this->companyContext->requireCompany()->tenant->vertical;

        $validator->after(function (Validator $validator) use ($vertical): void {
            if ($vertical !== Vertical::Parapharmacy && $this->exists('parapharmacy_metadata')) {
                $validator->errors()->add(
                    'parapharmacy_metadata',
                    'Parapharmacy metadata is not allowed for this business type.'
                );
            }

            if (! $vertical->isAutomotive() && $this->exists('automotive_metadata')) {
                $validator->errors()->add(
                    'automotive_metadata',
                    'Automotive metadata is not allowed for this business type.'
                );
            }

            $this->validateTypePhysicalCoherence($validator);
        });

        $this->attachMarginBandRule($validator, 'minimum_margin_override', 'target_margin_override');
    }

    /**
     * Validate that type and is_physical do not contradict each other.
     * Runs after prepareForValidation() may have derived is_physical; a derived
     * value is always coherent, so in practice this only rejects a caller-supplied
     * contradiction (service+physical, or part/consumable+non-physical).
     */
    private function validateTypePhysicalCoherence(Validator $validator): void
    {
        $typeValue = $this->input('type');
        $isPhysical = $this->input('is_physical');

        if ($typeValue === null || $isPhysical === null) {
            return;
        }

        $type = is_string($typeValue) ? ProductType::tryFrom($typeValue) : null;

        if ($type === null) {
            return;
        }

        $isPhysicalBool = filter_var($isPhysical, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isPhysicalBool === null) {
            return;
        }

        if ($type === ProductType::Service && $isPhysicalBool === true) {
            $validator->errors()->add(
                'is_physical',
                'A service product cannot be physical. Set is_physical to false or omit it.'
            );

            return;
        }

        if (in_array($type, [ProductType::Part, ProductType::Consumable], true) && $isPhysicalBool === false) {
            $validator->errors()->add(
                'is_physical',
                'A part or consumable product must be physical. Set is_physical to true or omit it.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $tenantId = $user?->tenant_id;
        $productId = $this->route('product');

        $company = $this->companyContext->requireCompany();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'brand_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('brands', 'id')->where('tenant_id', $company->tenant_id),
            ],
            'sku' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($productId),
            ],
            'type' => ['sometimes', 'nullable', new Enum(ProductType::class)],
            'category_id' => ['sometimes', 'nullable', 'integer', ScopedExists::company('categories', $company->id)],
            'is_physical' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pricing_mode' => ['sometimes', new Enum(PricingMode::class)],
            'target_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'minimum_margin_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            // api.unmapped.011 (api.catalog): tax_configurations is a
            // country-scoped global reference table (no tenant_id /
            // company_id columns; partitioned by country_code). Cross-tenant
            // assignment is structurally impossible because the table holds
            // public reference data shared across every tenant in a country.
            // See docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md.
            // Mirrors the api.catalog.002 / 005 precedent (UpdateCompositeItemRequest /
            // StoreCompositeItemRequest) closed via the same annotation.
            // Defense-in-depth coherence check enforced below via
            // TaxConfigurationCountryCoherent rule (Task 11 parity with categories).
            'default_tax_configuration_id' => [
                'sometimes', 'nullable', 'uuid', 'exists:tax_configurations,id',
                new TaxConfigurationCountryCoherent($company->country_code),
            ],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Unit of measure FK — drives quantity precision (decimals/step).
            'unit_id' => ['sometimes', 'nullable', 'exists:units,id'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'units_per_pack' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'shelf_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reorder_point' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reorder_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
            'is_active' => ['sometimes', 'boolean'],
            'is_active_for_ecommerce' => ['sometimes', 'boolean'],
            'requires_batch_tracking' => ['sometimes', 'boolean'],
            'default_shelf_life_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'oem_numbers' => ['sometimes', 'nullable', 'array'],
            'oem_numbers.*' => ['string', 'max:100'],
            'cross_references' => ['sometimes', 'nullable', 'array'],
            'cross_references.*.brand' => ['required_with:cross_references', 'string', 'max:100'],
            'cross_references.*.reference' => ['required_with:cross_references', 'string', 'max:100'],

            // Parapharmacy metadata (vertical-specific)
            'parapharmacy_metadata' => ['sometimes', 'array'],
            'parapharmacy_metadata.category' => ['required_with:parapharmacy_metadata', new Enum(ParapharmacyCategory::class)],
            'parapharmacy_metadata.dosage_form' => ['nullable', new Enum(DosageForm::class)],
            'parapharmacy_metadata.active_ingredients' => ['nullable', 'array'],
            'parapharmacy_metadata.active_ingredients.*.name' => ['required', 'string', 'max:255'],
            'parapharmacy_metadata.active_ingredients.*.concentration' => ['nullable', 'string', 'max:100'],
            'parapharmacy_metadata.key_components' => ['nullable', 'array'],
            'parapharmacy_metadata.key_components.*' => ['string', 'max:255'],
            'parapharmacy_metadata.usage_instructions' => ['nullable', 'string', 'max:5000'],
            'parapharmacy_metadata.warnings' => ['nullable', 'string', 'max:5000'],
            'parapharmacy_metadata.contraindications' => ['nullable', 'string', 'max:5000'],
            'parapharmacy_metadata.minimum_age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'parapharmacy_metadata.age_restriction' => ['nullable', new Enum(AgeRestriction::class)],
            'parapharmacy_metadata.requires_consultation' => ['sometimes', 'boolean'],
            'parapharmacy_metadata.regulatory_code' => ['nullable', 'string', 'max:100'],
            'parapharmacy_metadata.health_claims' => ['nullable', 'array'],
            'parapharmacy_metadata.health_claims.*' => ['string', 'max:500'],
            'parapharmacy_metadata.certifications' => ['nullable', 'array'],
            'parapharmacy_metadata.certifications.*.type' => ['required', 'string', 'max:100'],
            'parapharmacy_metadata.certifications.*.code' => ['nullable', 'string', 'max:100'],
            'parapharmacy_metadata.storage_requirements' => ['nullable', 'string', 'max:500'],

            // Automotive metadata (vertical-specific)
            'automotive_metadata' => ['sometimes', 'array'],
            'automotive_metadata.platform_article_id' => ['nullable', 'uuid'],
            'automotive_metadata.platform_link_status' => ['nullable', new Enum(PlatformLinkStatus::class)],
            'automotive_metadata.article_number' => ['nullable', 'string', 'max:100'],
            'automotive_metadata.supplier_brand' => ['nullable', 'string', 'max:200'],
            'automotive_metadata.product_group_name' => ['nullable', 'string', 'max:200'],
            'automotive_metadata.brand_quality_tier' => ['nullable', new Enum(BrandQualityTier::class)],
            'automotive_metadata.article_status' => ['nullable', new Enum(AutomotiveArticleStatus::class)],
            'automotive_metadata.confidence_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'automotive_metadata.data_source' => ['nullable', 'string', 'max:50'],
            'automotive_metadata.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'automotive_metadata.dimensions' => ['nullable', 'array'],
            'automotive_metadata.superseded_by_product_id' => ['nullable', 'uuid'],
            'automotive_metadata.is_universal_fit' => ['sometimes', 'boolean'],
            'automotive_metadata.notes' => ['nullable', 'string', 'max:5000'],
            'automotive_metadata.tire_width' => ['nullable', 'integer', 'min:100', 'max:400'],
            'automotive_metadata.tire_aspect_ratio' => ['nullable', 'integer', 'min:20', 'max:90'],
            'automotive_metadata.tire_rim_diameter' => ['nullable', 'integer', 'min:10', 'max:30'],
            'automotive_metadata.tire_speed_rating' => ['nullable', 'string', 'max:5'],
            'automotive_metadata.tire_load_index' => ['nullable', 'integer', 'min:50', 'max:200'],
            'automotive_metadata.tire_season' => ['nullable', 'string', 'max:20'],
            'automotive_metadata.glass_type' => ['nullable', 'string', 'max:50'],
            'automotive_metadata.glass_tinting' => ['nullable', 'string', 'max:20'],
            'automotive_metadata.cross_references' => ['sometimes', 'nullable', 'array'],
            'automotive_metadata.cross_references.*.reference_type' => ['required', new Enum(CrossReferenceType::class)],
            'automotive_metadata.cross_references.*.reference_number' => ['required', 'string', 'max:200'],
            'automotive_metadata.cross_references.*.manufacturer_name' => ['nullable', 'string', 'max:200'],
            'automotive_metadata.vehicles' => ['sometimes', 'nullable', 'array'],
            'automotive_metadata.vehicles.*.vehicle_type' => ['required', new Enum(VehicleTypeRef::class)],
            'automotive_metadata.vehicles.*.vehicle_display' => ['required', 'string', 'max:500'],
            'automotive_metadata.vehicles.*.platform_vehicle_id' => ['nullable', 'uuid'],
            'automotive_metadata.vehicles.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'automotive_metadata.vehicles.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'automotive_metadata.vehicles.*.notes' => ['nullable', 'string', 'max:500'],
            'automotive_metadata.criteria' => ['sometimes', 'nullable', 'array'],
            'automotive_metadata.criteria.*.criteria_key' => ['required', 'string', 'max:100'],
            'automotive_metadata.criteria.*.criteria_label' => ['required', 'string', 'max:200'],
            'automotive_metadata.criteria.*.value' => ['required', 'string', 'max:500'],
            'automotive_metadata.criteria.*.unit' => ['nullable', 'string', 'max:20'],
            'automotive_metadata.criteria.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sale_price.regex' => 'Sale price must have at most 3 decimal places.',
            'purchase_price.regex' => 'Purchase price must have at most 3 decimal places.',
            'tax_rate.regex' => 'Tax rate must have at most 2 decimal places.',
            'reorder_point.regex' => 'Reorder point must have at most 4 decimal places.',
            'reorder_quantity.regex' => 'Reorder quantity must have at most 4 decimal places.',
            'target_margin_override.regex' => 'Target margin override must have at most 2 decimal places.',
            'minimum_margin_override.regex' => 'Minimum margin override must have at most 2 decimal places.',
        ];
    }
}
