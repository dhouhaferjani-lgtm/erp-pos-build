<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum BrandQualityTier: string
{
    case Oe = 'oe';
    case Oes = 'oes';
    case PremiumAftermarket = 'premium_aftermarket';
    case Aftermarket = 'aftermarket';
    case Economy = 'economy';

    public function label(): string
    {
        return match ($this) {
            self::Oe => 'OE (Original Equipment)',
            self::Oes => 'OES (OE Supplier Spec)',
            self::PremiumAftermarket => 'Premium Aftermarket',
            self::Aftermarket => 'Aftermarket',
            self::Economy => 'Economy',
        };
    }
}
