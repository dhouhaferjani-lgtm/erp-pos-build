<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;

final class CountryAccountingCapabilitiesService implements CountryAccountingCapabilities
{
    private const CAPABILITY_VERSION = 'v1';

    /** @var list<string> */
    private const STAMP_DUTY_COUNTRIES = ['TN'];

    public function supportsStampDuty(string $countryCode): bool
    {
        return in_array(strtoupper(trim($countryCode)), self::STAMP_DUTY_COUNTRIES, true);
    }

    public function version(): string
    {
        return self::CAPABILITY_VERSION;
    }
}
