<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Enums;

/**
 * Whether the customer waits on-site (Waiter), drops the vehicle off, or has
 * a scheduled pickup. Drives the bay scheduler hints and customer notifications.
 */
enum WaitType: string
{
    case Waiter = 'waiter';
    case DropOff = 'drop_off';
    case PickupScheduled = 'pickup_scheduled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
