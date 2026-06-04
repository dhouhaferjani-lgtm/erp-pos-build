<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use App\Modules\Company\Application\Services\CountryTaxIdentityConfig;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CreateLocationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'type' => ['required', new Enum(LocationType::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:100'],
            'address_postal_code' => ['nullable', 'string', 'max:20'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'pos_enabled' => ['nullable', 'boolean'],
            'tax_id' => [
                'nullable',
                'string',
                'max:50',
                function (string $_attribute, string|array|null $value, Closure $fail): void {
                    $country = strtoupper((string) ($this->input('address_country') ?? ''));
                    if (is_string($value) && $value !== '' && $country !== '' && ! CountryTaxNumberRules::matches($country, $value)) {
                        $fail('The branch tax ID format is invalid for '.$country.'.');
                    }
                },
            ],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'legal_identifiers' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = (string) $this->input('type');
            $country = strtoupper((string) ($this->input('address_country') ?? ''));
            $taxId = $this->input('tax_id');

            if ($type !== LocationType::Shop->value || $country === '') {
                return;
            }

            if ((new CountryTaxIdentityConfig)->isBranchTaxIdRequired($country) && ($taxId === null || $taxId === '')) {
                $validator->errors()->add(
                    'tax_id',
                    'A branch tax ID is required for a sellable shop in '.$country.'.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Location name is required.',
            'name.max' => 'Location name cannot exceed 100 characters.',
            'type.required' => 'Location type is required.',
            'code.max' => 'Location code cannot exceed 20 characters.',
        ];
    }
}
