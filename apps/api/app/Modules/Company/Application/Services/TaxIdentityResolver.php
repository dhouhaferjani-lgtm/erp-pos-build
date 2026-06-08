<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Location;
use App\Shared\Contracts\Company\TaxIdentityData;

final class TaxIdentityResolver
{
    public function resolve(Location $location): TaxIdentityData
    {
        $company = $location->company()->withTrashed()->first();

        return new TaxIdentityData(
            taxId: $location->tax_id ?? $company?->tax_id,
            vatNumber: $location->vat_number ?? $company?->vat_number,
            legalIdentifiers: array_merge(
                $this->legalIdentifiers($company?->legal_identifiers),
                $this->legalIdentifiers($location->legal_identifiers),
            ),
            countryCode: $location->address_country ?? $company?->country_code,
        );
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
