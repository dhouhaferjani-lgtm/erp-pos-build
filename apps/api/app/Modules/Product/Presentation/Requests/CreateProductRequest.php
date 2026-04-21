<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\AutomotiveArticleStatus;
use App\Modules\Product\Domain\Enums\BrandQualityTier;
use App\Modules\Product\Domain\Enums\CrossReferenceType;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $tenantId = $user?->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['nullable', new Enum(ProductType::class)],
            'is_physical' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_tax_configuration_id' => ['nullable', 'uuid', 'exists:tax_configurations,id'],
            'unit' => ['nullable', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'oem_numbers' => ['nullable', 'array'],
            'oem_numbers.*' => ['string', 'max:100'],
            'cross_references' => ['nullable', 'array'],
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
            'automotive_metadata.cross_references' => ['nullable', 'array'],
            'automotive_metadata.cross_references.*.reference_type' => ['required', new Enum(CrossReferenceType::class)],
            'automotive_metadata.cross_references.*.reference_number' => ['required', 'string', 'max:200'],
            'automotive_metadata.cross_references.*.manufacturer_name' => ['nullable', 'string', 'max:200'],
            'automotive_metadata.vehicles' => ['nullable', 'array'],
            'automotive_metadata.vehicles.*.vehicle_type' => ['required', new Enum(VehicleTypeRef::class)],
            'automotive_metadata.vehicles.*.vehicle_display' => ['required', 'string', 'max:500'],
            'automotive_metadata.vehicles.*.platform_vehicle_id' => ['nullable', 'uuid'],
            'automotive_metadata.vehicles.*.year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'automotive_metadata.vehicles.*.year_to' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'automotive_metadata.vehicles.*.notes' => ['nullable', 'string', 'max:500'],
            'automotive_metadata.criteria' => ['nullable', 'array'],
            'automotive_metadata.criteria.*.criteria_key' => ['required', 'string', 'max:100'],
            'automotive_metadata.criteria.*.criteria_label' => ['required', 'string', 'max:200'],
            'automotive_metadata.criteria.*.value' => ['required', 'string', 'max:500'],
            'automotive_metadata.criteria.*.unit' => ['nullable', 'string', 'max:20'],
            'automotive_metadata.criteria.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
