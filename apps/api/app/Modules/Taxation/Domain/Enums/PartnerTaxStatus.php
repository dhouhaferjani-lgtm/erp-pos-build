<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum PartnerTaxStatus: string
{
    case REGISTERED = 'REGISTERED';
    case NON_REGISTERED = 'NON_REGISTERED';
    case EXEMPT = 'EXEMPT';

    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'VAT Registered',
            self::NON_REGISTERED => 'Not VAT Registered',
            self::EXEMPT => 'Tax Exempt',
        };
    }

    public function requiresExemptionCertificate(): bool
    {
        return $this === self::EXEMPT;
    }
}
