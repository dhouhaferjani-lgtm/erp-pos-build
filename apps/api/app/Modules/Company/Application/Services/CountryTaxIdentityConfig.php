<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

final class CountryTaxIdentityConfig
{
    public function isBranchTaxIdRequired(string $countryCode): bool
    {
        return $this->forCountry($countryCode)['branch_tax_id_required'];
    }

    public function mode(string $countryCode): string
    {
        return $this->forCountry($countryCode)['mode'];
    }

    /** @return array{mode: string, branch_tax_id_required: bool} */
    private function forCountry(string $countryCode): array
    {
        /** @var array<string, array{mode: string, branch_tax_id_required: bool}> $countries */
        $countries = config('tax_identity.countries', []);
        /** @var array{mode: string, branch_tax_id_required: bool} $default */
        $default = config('tax_identity.default', [
            'mode' => 'none',
            'branch_tax_id_required' => false,
        ]);

        return $countries[strtoupper($countryCode)] ?? $default;
    }
}
