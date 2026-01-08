<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum CompanyTaxStatus: string
{
    case REGISTERED = 'REGISTERED';
    case NON_REGISTERED = 'NON_REGISTERED';

    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'VAT Registered (Assujetti)',
            self::NON_REGISTERED => 'Not VAT Registered (Non-Assujetti)',
        };
    }

    public function canRecoverVAT(): bool
    {
        return $this === self::REGISTERED;
    }
}
