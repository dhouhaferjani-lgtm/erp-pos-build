<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

/**
 * Technician skill-level taxonomy.
 *
 * Drives UI badges and (later) payroll-band defaults. Distinct from specialty:
 * a technician has one level but can hold multiple specialties.
 */
enum SkillLevel: string
{
    case Apprentice = 'apprentice';
    case Junior = 'junior';
    case General = 'general';
    case Senior = 'senior';
    case Master = 'master';
    case Specialist = 'specialist';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
