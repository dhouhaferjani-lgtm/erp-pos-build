<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

final class UnpublishedAssignedTemplateException extends CountryDefaultsProvisioningUnavailableException
{
    public static function forCountry(string $countryCode): self
    {
        return new self("Assignment {$countryCode} does not reference a published template.");
    }
}
