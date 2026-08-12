<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

use DomainException;

final class TemplateRecertificationRequiredException extends DomainException
{
    public static function forAssignment(string $countryCode, string $storedVersion, string $currentVersion): self
    {
        return new self(
            "Assignment {$countryCode} has stale capability registry version {$storedVersion}; current version is {$currentVersion}.",
        );
    }
}
