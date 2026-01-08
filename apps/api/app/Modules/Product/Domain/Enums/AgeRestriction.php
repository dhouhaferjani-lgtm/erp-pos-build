<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

/**
 * Age restrictions for parapharmacy products.
 */
enum AgeRestriction: string
{
    case AdultOnly = 'adult_only';
    case ChildrenOnly = 'children_only';
    case AllAges = 'all_ages';

    /**
     * Get human-readable label for the age restriction.
     */
    public function label(): string
    {
        return match ($this) {
            self::AdultOnly => 'Adults Only',
            self::ChildrenOnly => 'Children Only',
            self::AllAges => 'All Ages',
        };
    }

    /**
     * Get recommended minimum age if applicable.
     */
    public function suggestedMinimumAge(): ?int
    {
        return match ($this) {
            self::AdultOnly => 18,
            self::ChildrenOnly => 0,
            self::AllAges => null,
        };
    }
}
