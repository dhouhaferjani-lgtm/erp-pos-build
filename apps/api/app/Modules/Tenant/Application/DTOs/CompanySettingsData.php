<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\DTOs;

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\DTOs\EffectiveCountCorrectionGlPosting;
use App\Modules\Inventory\Application\DTOs\EffectiveValuationMode;
use App\Modules\Inventory\Application\Services\CountCorrectionGlPostingResolver;
use App\Modules\Inventory\Application\Services\InventoryValuationModeResolver;
use App\Modules\Tenant\Domain\Tenant;

class CompanySettingsData
{
    /**
     * @param  array<string, string|null>|null  $address
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName,
        public readonly ?string $taxId,
        public readonly ?string $registrationNumber,
        public readonly ?array $address,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $website,
        public readonly ?string $logoUrl,
        public readonly ?string $primaryColor,
        public readonly ?string $countryCode,
        public readonly ?string $currencyCode,
        public readonly ?string $timezone,
        public readonly ?string $dateFormat,
        public readonly ?string $locale,
        public readonly bool $lineDesignationOverrideEnabled,
        /**
         * DPA Wave 3 T10 — the RESOLVED inventory valuation mode and where it
         * came from. Read-only on the wire: the settings surface renders it,
         * never edits it (an editable control whose only valid value is the
         * default is a support trap).
         */
        public readonly string $inventoryValuationMode,
        public readonly string $inventoryValuationModeSource,
        /**
         * Lane P-1 — the RESOLVED count-correction GL-posting answer, where it
         * came from, and the tenant's OWN override (null = inheriting).
         *
         * Unlike the valuation mode this one IS editable: both values are
         * meaningful and a tenant may legitimately want stock-take differences
         * kept out of the ledger. The override is surfaced separately from the
         * resolved answer so the settings form can tell "off because I said so"
         * from "off because my jurisdiction says so".
         */
        public readonly bool $countCorrectionGlPostingEnabled,
        public readonly string $countCorrectionGlPostingSource,
        public readonly ?bool $countCorrectionGlPostingOverride,
    ) {}

    /**
     * Create from a Tenant model.
     *
     * @return array{name: string, legal_name: string|null, tax_id: string|null, registration_number: string|null, address: array{street: string|null, city: string|null, postal_code: string|null, country: string|null}, phone: string|null, email: string|null, website: string|null, logo_url: string|null, primary_color: string|null, country_code: string|null, currency_code: string|null, timezone: string|null, date_format: string|null, locale: string|null, line_designation_override_enabled: bool, inventory_valuation_mode: string, inventory_valuation_mode_source: string, count_correction_gl_posting_enabled: bool, count_correction_gl_posting_source: string, count_correction_gl_posting_override: bool|null}
     */
    public static function fromTenant(Tenant $tenant): array
    {
        $address = $tenant->address ?? [];

        return [
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'tax_id' => $tenant->tax_id,
            'registration_number' => $tenant->registration_number,
            'address' => [
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country' => $address['country'] ?? $tenant->country_code,
            ],
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'website' => $tenant->website,
            'logo_url' => $tenant->logo_path ? asset('storage/'.$tenant->logo_path) : null,
            'primary_color' => $tenant->primary_color,
            'country_code' => $tenant->country_code,
            'currency_code' => $tenant->currency_code,
            'timezone' => $tenant->timezone,
            'date_format' => $tenant->date_format,
            'locale' => $tenant->locale,
            'line_designation_override_enabled' => false,
            // A tenant-level view has no company, so no resolvable mode: report
            // the system default and say the source is the system, rather than
            // omit the keys and give the UI a shape that changes per endpoint.
            'inventory_valuation_mode' => InventoryValuationModeResolver::SYSTEM_DEFAULT->value,
            'inventory_valuation_mode_source' => EffectiveValuationMode::SOURCE_SYSTEM,
            // Same reasoning as the line above: a tenant-level view has no
            // company, so nothing is resolvable. Report the system default and
            // say so, rather than omit the keys and hand the UI a shape that
            // changes per endpoint.
            //
            // Gate r1 F-4: this was a literal `true`, which made the documented
            // deployment-wide kill switch (INVENTORY_COUNT_CORRECTION_GL_POSTING_ENABLED=false)
            // invisible on exactly this surface. It now asks the same authority
            // the sibling line asks. `systemDefault()` reads config and touches
            // no database, so constructing the resolver here costs nothing and
            // introduces no container lookup (`app()` is forbidden, rule 13);
            // the two resolving call sites still inject it.
            'count_correction_gl_posting_enabled' => (new CountCorrectionGlPostingResolver)->systemDefault(),
            'count_correction_gl_posting_source' => EffectiveCountCorrectionGlPosting::SOURCE_SYSTEM,
            'count_correction_gl_posting_override' => null,
        ];
    }

    /**
     * Create from a Company model.
     *
     * @return array{name: string, legal_name: string|null, tax_id: string|null, registration_number: string|null, address: array{street: string|null, city: string|null, postal_code: string|null, country: string|null}, phone: string|null, email: string|null, website: string|null, logo_url: string|null, primary_color: string|null, country_code: string|null, currency_code: string|null, timezone: string|null, date_format: string|null, locale: string|null, line_designation_override_enabled: bool, inventory_valuation_mode: string, inventory_valuation_mode_source: string, count_correction_gl_posting_enabled: bool, count_correction_gl_posting_source: string, count_correction_gl_posting_override: bool|null}
     */
    public static function fromCompany(
        Company $company,
        EffectiveValuationMode $valuation,
        EffectiveCountCorrectionGlPosting $countCorrectionGlPosting,
    ): array {
        return [
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'registration_number' => $company->registration_number,
            'address' => [
                'street' => $company->address_street,
                'city' => $company->address_city,
                'postal_code' => $company->address_postal_code,
                'country' => $company->country_code,
            ],
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'logo_url' => $company->logo_path ? asset('storage/'.$company->logo_path) : null,
            'primary_color' => $company->primary_color,
            'country_code' => $company->country_code,
            'currency_code' => $company->currency,
            'timezone' => $company->timezone,
            'date_format' => $company->date_format,
            'locale' => $company->locale,
            'line_designation_override_enabled' => (bool) $company->line_designation_override_enabled,
            'inventory_valuation_mode' => $valuation->mode->value,
            'inventory_valuation_mode_source' => $valuation->source,
            'count_correction_gl_posting_enabled' => $countCorrectionGlPosting->enabled,
            'count_correction_gl_posting_source' => $countCorrectionGlPosting->source,
            'count_correction_gl_posting_override' => $company->count_correction_gl_posting_enabled,
        ];
    }
}
