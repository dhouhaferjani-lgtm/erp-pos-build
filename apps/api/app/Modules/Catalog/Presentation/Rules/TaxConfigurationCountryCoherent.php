<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Rules;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * api.catalog.023 round-2: Defense-in-depth coherence check for
 * default_tax_configuration_id on composite items.
 *
 * tax_configurations is a country-scoped global reference table (no
 * tenant_id / company_id). TaxCalculationService selects applicable
 * configs by company.country_code at calculation time, so a mismatched
 * default_tax_configuration_id is silently ignored at runtime — no
 * cross-tenant data leak, but the field stores integrity garbage.
 *
 * This rule rejects a tax_configuration_id whose country_code differs
 * from the company's country_code at save time, providing an explicit
 * error instead of silent mismatch.
 */
class TaxConfigurationCountryCoherent implements ValidationRule
{
    public function __construct(
        private readonly string $companyCountryCode,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $config = TaxConfiguration::find($value);

        if ($config === null) {
            // Let the 'exists:tax_configurations,id' rule handle missing rows.
            return;
        }

        if ($config->country_code !== $this->companyCountryCode) {
            $fail(__('The selected :attribute does not belong to the company\'s country (:country).', [
                'attribute' => $attribute,
                'country' => $this->companyCountryCode,
            ]));
        }
    }
}
