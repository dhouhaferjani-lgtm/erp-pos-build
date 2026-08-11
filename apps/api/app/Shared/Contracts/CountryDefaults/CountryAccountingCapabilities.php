<?php

declare(strict_types=1);

namespace App\Shared\Contracts\CountryDefaults;

interface CountryAccountingCapabilities
{
    public function supportsStampDuty(string $countryCode): bool;

    public function version(): string;
}
