<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

use DomainException;

abstract class CountryDefaultsProvisioningUnavailableException extends DomainException
{
    final public function publicCode(): string
    {
        return 'COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE';
    }

    final public function translationKey(): string
    {
        return 'country_defaults.errors.provisioning_unavailable';
    }
}
