<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use App\Modules\Company\Application\Services\CountryTaxIdentityConfig;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'type' => ['sometimes', new Enum(LocationType::class)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'pos_enabled' => ['sometimes', 'boolean'],
            'tax_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                function (string $_attribute, string|int|float|bool|array|null $value, Closure $fail): void {
                    $country = $this->taxValidationCountry();
                    if (is_string($value) && $value !== '' && $country !== '' && ! CountryTaxNumberRules::matches($country, $value)) {
                        $fail('The branch tax ID format is invalid for '.$country.'.');
                    }
                },
            ],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'legal_identifiers' => ['sometimes', 'nullable', 'array'],
            'legal_identifiers.siret' => [
                'sometimes',
                'nullable',
                'string',
                function (string $_attribute, string|int|float|bool|array|null $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && $this->taxValidationCountry() === 'FR' && ! CountryTaxNumberRules::matches('FR', $value)) {
                        $fail('The branch SIRET in legal identifiers is invalid.');
                    }
                },
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Clearing a branch tax ID re-inherits the company value. Allow it
            // EXCEPT for a sellable Shop in a country that requires a branch tax
            // ID (mirrors the create-time required gate so a required FR/TN shop
            // cannot be silently reverted to the HQ identity).
            $all = $this->all();
            if (! array_key_exists('tax_id', $all)) {
                return; // tax_id not being modified
            }

            $taxId = $all['tax_id'];
            if (! ($taxId === null || $taxId === '')) {
                return; // setting a value, not clearing
            }

            $country = $this->taxValidationCountry();
            if ($country === '' || $this->effectiveType() !== LocationType::Shop->value) {
                return;
            }

            if ((new CountryTaxIdentityConfig)->isBranchTaxIdRequired($country)) {
                $validator->errors()->add(
                    'tax_id',
                    'A branch tax ID is required for a sellable shop in '.$country.'; it cannot be cleared.'
                );
            }
        });
    }

    private function effectiveType(): string
    {
        $submitted = $this->input('type');
        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $locationId = $this->route('location');
        if (! is_string($locationId) || $locationId === '') {
            return '';
        }

        $query = Location::query()->whereKey($locationId);
        $companyId = $this->header('X-Company-Id');
        if (is_string($companyId) && $companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $type = $query->value('type');

        return $type instanceof LocationType ? $type->value : (is_string($type) ? $type : '');
    }

    private function taxValidationCountry(): string
    {
        $submitted = $this->input('address_country');
        if (is_string($submitted) && $submitted !== '') {
            return strtoupper($submitted);
        }

        $locationId = $this->route('location');
        if (! is_string($locationId) || $locationId === '') {
            return '';
        }

        $query = Location::query()->whereKey($locationId);
        $companyId = $this->header('X-Company-Id');
        if (is_string($companyId) && $companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $country = $query->value('address_country');

        return is_string($country) ? strtoupper($country) : '';
    }
}
