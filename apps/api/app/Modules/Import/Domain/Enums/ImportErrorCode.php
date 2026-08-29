<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

/**
 * G-12 created this shared enum under spec §3.1d's earliest-lane rule.
 * G-6a, G-4, G-2, G-8, G-1, and G-5 add their own cases. isJobLevel()
 * must remain an exhaustive match with no default so every new case makes
 * its row-level versus job-level decision explicitly.
 */
enum ImportErrorCode: string
{
    case UnitsNotSeeded = 'units_not_seeded';

    public function isJobLevel(): bool
    {
        return match ($this) {
            self::UnitsNotSeeded => true,
        };
    }
}
