<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Models\Country;
use App\Modules\Company\Domain\Location;
use App\Shared\Contracts\Company\TaxIdentityData;

final class TaxIdentityResolver
{
    public function resolve(Location $location): TaxIdentityData
    {
        $company = $location->company()->withTrashed()->first();

        $countryCode = $location->address_country ?? $company?->country_code;

        return new TaxIdentityData(
            taxId: $location->tax_id ?? $company?->tax_id,
            vatNumber: $location->vat_number ?? $company?->vat_number,
            legalIdentifiers: array_merge(
                $this->legalIdentifiers($company?->legal_identifiers),
                $this->legalIdentifiers($location->legal_identifiers),
            ),
            countryCode: $countryCode,
            taxIdLabel: $this->labelForCountry($countryCode),
        );
    }

    /**
     * Resolve the country-specific label for the tax id (e.g. "Matricule Fiscal"
     * for TN, "SIREN" for FR). Falls back to null when the country is unknown,
     * letting render templates use their generic locale label.
     */
    public function labelForCountry(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }

        $label = Country::query()->find(strtoupper($countryCode))?->tax_id_label;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * @param  array<string, string|int|float|bool|null>|null  $identifiers
     * @return array<string, string|int|float|bool|null>
     */
    private function legalIdentifiers(?array $identifiers): array
    {
        return $identifiers ?? [];
    }
}
