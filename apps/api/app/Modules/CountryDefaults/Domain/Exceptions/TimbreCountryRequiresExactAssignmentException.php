<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

final class TimbreCountryRequiresExactAssignmentException extends CountryDefaultsProvisioningUnavailableException
{
    public static function forCountry(string $countryCode): self
    {
        return new self("Timbre-capable country {$countryCode} requires an exact template assignment.");
    }
}
