<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Company;

final class TaxIdentityData
{
    /**
     * @param  array<string, string|int|float|bool|null>  $legalIdentifiers
     */
    public function __construct(
        public readonly ?string $taxId,
        public readonly ?string $vatNumber,
        public readonly array $legalIdentifiers,
        public readonly ?string $countryCode,
        public readonly ?string $taxIdLabel = null,
    ) {}
}
