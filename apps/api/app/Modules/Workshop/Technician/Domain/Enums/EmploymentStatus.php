<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain\Enums;

/**
 * High-level employment status. Orthogonal to `is_active` flag on the profile —
 * a profile can be active (unsoft-deleted) but employment paused (`on_leave`),
 * in which case the tech cannot be assigned to new work orders.
 */
enum EmploymentStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Terminated = 'terminated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
