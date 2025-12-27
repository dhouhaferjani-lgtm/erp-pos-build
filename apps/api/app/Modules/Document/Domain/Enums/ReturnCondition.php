<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * Condition of returned products.
 *
 * Used to determine if products can be restocked for resale.
 */
enum ReturnCondition: string
{
    case Unopened = 'unopened';
    case Used = 'used';
    case Damaged = 'damaged';
    case Unusable = 'unusable';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::Unopened => 'Unopened / New Condition',
            self::Used => 'Used / Good Condition',
            self::Damaged => 'Damaged / Requires Repair',
            self::Unusable => 'Unusable / Scrap',
        };
    }

    /**
     * Check if product can be restocked for resale
     */
    public function canRestock(): bool
    {
        return match ($this) {
            self::Unopened, self::Used => true,
            self::Damaged, self::Unusable => false,
        };
    }
}
