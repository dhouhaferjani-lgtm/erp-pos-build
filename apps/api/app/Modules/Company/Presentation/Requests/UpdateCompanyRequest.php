<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\Enums\PriceEntryMode;
use App\Modules\Product\Presentation\Requests\Concerns\ValidatesMarginBand;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanyRequest extends FormRequest
{
    use ValidatesMarginBand;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Basic info
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],

            // Address
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_street_2' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_state' => ['nullable', 'string', 'max:100'],
            'address_postal_code' => ['nullable', 'string', 'max:20'],

            // Tax info
            'tax_id' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'default_tax_rate' => ['nullable', 'string', 'max:10'],
            'default_tax_configuration_id' => ['nullable', 'uuid', 'exists:tax_configurations,id'],
            'tax_status' => ['nullable', Rule::enum(CompanyTaxStatus::class)],

            // Margin defaults
            'default_target_margin' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'default_minimum_margin' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'default_max_discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'discount_floor_mode' => ['sometimes', Rule::enum(DiscountFloorMode::class)],
            'price_entry_mode' => ['sometimes', Rule::enum(PriceEntryMode::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->attachMarginBandRule($validator, 'default_minimum_margin', 'default_target_margin');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Company name is required.',
            'email.email' => 'Invalid email format.',
            'website.url' => 'Invalid website URL.',
            'default_tax_configuration_id.exists' => 'Selected tax configuration does not exist.',
        ];
    }
}
