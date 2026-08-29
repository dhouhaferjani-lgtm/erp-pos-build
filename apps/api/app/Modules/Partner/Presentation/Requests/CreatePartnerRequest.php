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
use App\Shared\Domain\Validation\CountryTaxNumberRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class CreatePartnerRequest extends FormRequest
{
    use ValidatesPartnerBankAccounts;

    private ?string $resolvedTaxCountryCode = null;

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
        $company = $this->companyContext->requireCompany();

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
                    ->where('company_id', $company->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    // A partner that omits `country_code` is NOT unvalidated:
                    // it falls back to the company's own country. Without this
                    // fallback the check self-disabled for every client that
                    // never sends the field (research spec 2026-08-23 §3.3).
                    $countryCode = $this->resolvedTaxCountryCode();
                    if ($countryCode === '') {
                        return;
                    }

                    if (! $this->validateVatNumber($countryCode, (string) $value)) {
                        $fail($this->vatFormatFailureMessage($countryCode));
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
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'invoice_consolidation' => ['sometimes', 'boolean'],
            'consolidation_frequency' => [
                'nullable',
                new Enum(ConsolidationFrequency::class),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'street_address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
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
        $messages = [
            'credit_limit.regex' => 'Credit limit must have at most 3 decimal places.',
            'discount_percentage.regex' => 'Discount percentage must have at most 2 decimal places.',
        ];

        $vatNumber = $this->input('vat_number');
        $company = $this->companyContext->requireCompany();
        if (is_string($vatNumber) && Partner::onlyTrashed()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('vat_number', $vatNumber)
            ->exists()
        ) {
            $messages['vat_number.unique'] = "vat_held_by_deleted_partner: VAT {$vatNumber} is held by a soft-deleted partner; "
                .'purge the deleted record or choose a different VAT.';
        }

        return $messages;
    }

    /**
     * The country whose tax-number rule applies: the submitted `country_code`
     * when present, otherwise the acting company's own country.
     *
     * Genuinely-foreign partners are unaffected — they carry an explicit
     * `country_code`, which always wins.
     */
    private function resolvedTaxCountryCode(): string
    {
        if ($this->resolvedTaxCountryCode !== null) {
            return $this->resolvedTaxCountryCode;
        }

        $submitted = $this->input('country_code');
        if (is_string($submitted) && $submitted !== '') {
            return $this->resolvedTaxCountryCode = strtoupper($submitted);
        }

        $companyCountry = $this->companyContext->getCompany()?->country_code;

        return $this->resolvedTaxCountryCode = is_string($companyCountry)
            ? strtoupper($companyCountry)
            : '';
    }

    /**
     * Name the country the rule came from. When it was inferred rather than
     * selected, say so — "the selected country" is misleading on a request
     * that selected nothing (gate R1 F-2).
     */
    private function vatFormatFailureMessage(string $countryCode): string
    {
        $submitted = $this->input('country_code');

        if (is_string($submitted) && $submitted !== '') {
            return 'The VAT number format is invalid for '.$countryCode.'.';
        }

        return 'The VAT number format is invalid for '.$countryCode
            .' (inferred from your company; set the partner country to use another format).';
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
            default => true, // Allow any format for unknown countries
        };
    }
}
