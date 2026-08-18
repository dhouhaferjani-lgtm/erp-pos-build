<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Exceptions;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;

final class MissingCountryTemplateAssignmentException extends CountryDefaultsProvisioningUnavailableException
{
    public static function wildcard(TemplateDomain $domain): self
    {
        return new self("No pinned wildcard assignment exists for {$domain->value}.");
    }
}
