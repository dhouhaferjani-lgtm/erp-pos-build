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

    private ?string $resolvedTaxCountryCode = null;

    private ?Partner $storedPartner = null;

    private bool $storedPartnerLoaded = false;

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
     *
     * A vat_number resubmitted BYTE-IDENTICAL to the stored one is left
     * completely alone — the web form echoes every field back on every edit
     * (`PartnerForm.tsx:405`), so rewriting it here would silently mutate a
     * field the operator never touched.
     */
    protected function prepareForValidation(): void
    {
        $vatNumber = $this->input('vat_number');

        if (! is_string($vatNumber) || $vatNumber === '' || $vatNumber === $this->storedVatNumber()) {
            return;
        }

        $country = $this->resolvedTaxCountryCode();

        if ($country === '') {
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
        $company = $this->companyContext->requireCompany();

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
                    ->where('company_id', $company->id)
                    ->ignore($partnerId),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    // GRANDFATHER CLAUSE. Only a NEW or CHANGED matricule has
                    // to be sealable; an unchanged one is never re-litigated.
                    // Migration-imported partners carry `country_code = NULL`
                    // and an arbitrary tax id, and the web form echoes that id
                    // back on every edit — without this, renaming such a
                    // partner would 422 on a field nobody touched (gate R1 F-2).
                    if ((string) $value === (string) $this->storedVatNumber()) {
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
                        $fail($this->vatFormatFailureMessage($countryCode));
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
     * when present, else the partner's own stored country, else the acting
     * company's country.
     *
     * Genuinely-foreign partners are unaffected — their stored (or submitted)
     * `country_code` always wins over the company fallback.
     *
     * Memoized: this runs once in `prepareForValidation()` and once in the
     * validation closure, and `CompanyContext::getCompany()` issues a fresh
     * `Company::find` on every call.
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

        $stored = $this->storedPartnerAttribute('country_code');
        if ($stored !== null && $stored !== '') {
            return $this->resolvedTaxCountryCode = strtoupper($stored);
        }

        $companyCountry = $this->companyContext->getCompany()?->country_code;

        return $this->resolvedTaxCountryCode = is_string($companyCountry)
            ? strtoupper($companyCountry)
            : '';
    }

    /**
     * The vat_number currently persisted for the partner being updated, or
     * null when there is none. Used to grandfather an unchanged value.
     */
    private function storedVatNumber(): ?string
    {
        return $this->storedPartnerAttribute('vat_number');
    }

    /**
     * Read one attribute off the partner under update.
     *
     * Scoped by the acting company, mirroring `PartnerController::update()`
     * (`PartnerController.php:263-265`): in a multi-company tenant an id from
     * another company must not seed the validation country (gate R1 F-8).
     * The whole row is fetched once and memoized.
     */
    private function storedPartnerAttribute(string $attribute): ?string
    {
        if (! $this->storedPartnerLoaded) {
            $this->storedPartnerLoaded = true;

            $partnerId = $this->route('partner');
            $companyId = $this->companyContext->getCompanyId();

            if (is_string($partnerId) && $partnerId !== '' && $companyId !== null) {
                $this->storedPartner = Partner::query()
                    ->whereKey($partnerId)
                    ->where('company_id', $companyId)
                    ->first(['country_code', 'vat_number']);
            }
        }

        $value = $this->storedPartner?->getAttribute($attribute);

        return is_string($value) ? $value : null;
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
            .' (inferred from this partner or your company; set the partner country to use another format).';
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
