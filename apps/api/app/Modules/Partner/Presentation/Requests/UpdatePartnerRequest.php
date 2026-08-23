<?php

declare(strict_types=1);

namespace App\Modules\Partner\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Enums\PaymentTerms;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Partner\Presentation\Requests\Concerns\ValidatesPartnerBankAccounts;
use App\Shared\Banking\Contracts\BankAccountValidatorInterface;
use App\Shared\Domain\Enums\SkinType;
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePartnerRequest extends FormRequest
{
    use ValidatesPartnerBankAccounts;

    public function __construct(
        private readonly BankAccountValidatorInterface $bankAccountValidator,
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Canonicalize the tax number to the STORED form before any rule runs, so
     * the value that is validated is byte-identical to the value that is
     * persisted and later sealed into a fiscal payload.
     */
    protected function prepareForValidation(): void
    {
        $country = $this->resolvedTaxCountryCode();
        $vatNumber = $this->input('vat_number');

        if ($country === '' || ! is_string($vatNumber) || $vatNumber === '') {
            return;
        }

        $this->merge([
            'vat_number' => CountryTaxNumberRules::normalizeForStorage($country, $vatNumber),
        ]);
    }

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
        $partnerId = $this->route('partner');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', new Enum(PartnerType::class)],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($partnerId),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'vat_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('partners', 'vat_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($partnerId),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    // A partner that omits `country_code` is NOT unvalidated:
                    // it falls back to the partner's own stored country, then
                    // to the company's. Without this fallback the check
                    // self-disabled for every client that never sends the
                    // field (research spec 2026-08-23 §3.3).
                    $countryCode = $this->resolvedTaxCountryCode();
                    if ($countryCode === '') {
                        return;
                    }

                    if (! $this->validateVatNumber($countryCode, (string) $value)) {
                        $fail('The VAT number format is invalid for the selected country.');
                    }
                },
            ],
            'customer_category' => ['sometimes', 'nullable', new Enum(CustomerCategory::class)],
            'company_legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_terms' => ['sometimes', 'nullable', new Enum(PaymentTerms::class)],
            'payment_terms_days' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:365',
            ],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'invoice_consolidation' => ['sometimes', 'boolean'],
            'consolidation_frequency' => [
                'sometimes',
                'nullable',
                new Enum(ConsolidationFrequency::class),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'skin_type' => ['sometimes', 'nullable', new Enum(SkinType::class)],
            'skin_advice_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street_address_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            ...$this->bankAccountRules((string) $tenantId),
        ];
    }

    protected function passedValidation(): void
    {
        $this->recordBankAccountValidity($this->bankAccountValidator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_limit.regex' => 'Credit limit must have at most 3 decimal places.',
            'discount_percentage.regex' => 'Discount percentage must have at most 2 decimal places.',
        ];
    }

    /**
     * The country whose tax-number rule applies: the submitted `country_code`
     * when present, else the partner's own stored country, else the acting
     * company's country.
     *
     * Genuinely-foreign partners are unaffected — their stored (or submitted)
     * `country_code` always wins over the company fallback.
     */
    private function resolvedTaxCountryCode(): string
    {
        $submitted = $this->input('country_code');
        if (is_string($submitted) && $submitted !== '') {
            return strtoupper($submitted);
        }

        $partnerId = $this->route('partner');
        if (is_string($partnerId) && $partnerId !== '') {
            $stored = Partner::query()->whereKey($partnerId)->value('country_code');
            if (is_string($stored) && $stored !== '') {
                return strtoupper($stored);
            }
        }

        $company = $this->companyContext->getCompany();
        $companyCountry = $company?->country_code;

        return is_string($companyCountry) ? strtoupper($companyCountry) : '';
    }

    private function validateVatNumber(string $countryCode, string $vatNumber): bool
    {
        return match ($countryCode) {
            'FR' => (bool) preg_match('/^FR[0-9A-Z]{2}[0-9]{9}$/', $vatNumber),
            // Delegated to the single source of truth so an accepted matricule
            // can never be rejected later by the sealed-payload gate.
            'TN' => CountryTaxNumberRules::matches('TN', $vatNumber),
            'IT' => (bool) preg_match('/^IT[0-9]{11}$/', $vatNumber),
            'GB' => (bool) preg_match('/^GB([0-9]{9}|[0-9]{12}|(HA|GD)[0-9]{3})$/', $vatNumber),
            default => true,
        };
    }
}
