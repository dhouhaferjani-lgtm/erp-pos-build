<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

use DomainException;

final class TimbreCountryRequiresExactAssignmentException extends DomainException
{
    public static function forCountry(string $countryCode): self
    {
        return new self("Timbre-capable country {$countryCode} requires an exact template assignment.");
    }
}
