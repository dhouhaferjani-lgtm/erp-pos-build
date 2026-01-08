<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation\Requests;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Enums\ProductType;
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
            'type' => ['required', new Enum(ProductType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
        ];
    }
}
