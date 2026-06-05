<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

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
        ];
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
