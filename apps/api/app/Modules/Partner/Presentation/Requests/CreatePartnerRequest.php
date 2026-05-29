<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests;

use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Enums\PaymentTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreatePartnerRequest extends FormRequest
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
            'type' => ['required', new Enum(PartnerType::class)],
            'customer_category' => ['nullable', new Enum(CustomerCategory::class)],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'vat_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'vat_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $countryCode = $this->input('country_code');
                    if ($countryCode === null) {
                        return;
                    }

                    if (! $this->validateVatNumber((string) $countryCode, (string) $value)) {
                        $fail('The VAT number format is invalid for the selected country.');
                    }
                },
            ],
            'company_legal_name' => ['nullable', 'string', 'max:255'],
            'business_registration_number' => ['nullable', 'string', 'max:100'],
            'payment_terms' => ['nullable', new Enum(PaymentTerms::class)],
            'payment_terms_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                'required_if:payment_terms,custom',
            ],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9999', 'regex:/^\d+(\.\d{1,4})?$/'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'invoice_consolidation' => ['sometimes', 'boolean'],
            'consolidation_frequency' => [
                'nullable',
                new Enum(ConsolidationFrequency::class),
                Rule::requiredIf(fn (): bool => (bool) $this->input('invoice_consolidation')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'street_address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_limit.regex' => 'Credit limit must have at most 4 decimal places.',
            'discount_percentage.regex' => 'Discount percentage must have at most 2 decimal places.',
        ];
    }

    private function validateVatNumber(string $countryCode, string $vatNumber): bool
    {
        return match ($countryCode) {
            'FR' => (bool) preg_match('/^FR[0-9A-Z]{2}[0-9]{9}$/', $vatNumber),
            'TN' => (bool) preg_match('/^[0-9]{7}[A-Z]{3}[0-9]{3}$/', $vatNumber),
            'IT' => (bool) preg_match('/^IT[0-9]{11}$/', $vatNumber),
            'GB' => (bool) preg_match('/^GB([0-9]{9}|[0-9]{12}|(HA|GD)[0-9]{3})$/', $vatNumber),
            default => true, // Allow any format for unknown countries
        };
    }
}
