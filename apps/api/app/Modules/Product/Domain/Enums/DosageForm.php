<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

/**
 * Dosage forms for parapharmacy products.
 */
enum DosageForm: string
{
    case Capsule = 'capsule';
    case Tablet = 'tablet';
    case Softgel = 'softgel';
    case Liquid = 'liquid';
    case Powder = 'powder';
    case Cream = 'cream';
    case Gel = 'gel';
    case Lotion = 'lotion';
    case Spray = 'spray';
    case Patch = 'patch';
    case Other = 'other';

    /**
     * Get human-readable label for the dosage form.
     */
    public function label(): string
    {
        return match ($this) {
            self::Capsule => 'Capsule',
            self::Tablet => 'Tablet',
            self::Softgel => 'Softgel',
            self::Liquid => 'Liquid',
            self::Powder => 'Powder',
            self::Cream => 'Cream',
            self::Gel => 'Gel',
            self::Lotion => 'Lotion',
            self::Spray => 'Spray',
            self::Patch => 'Patch',
            self::Other => 'Other',
        };
    }
}
