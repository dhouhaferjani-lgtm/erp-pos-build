<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Enums;

/**
 * Status of a refundable core-charge deposit attached to a Part line. Flips
 * from Outstanding → Returned via a matching CoreReturn line; Expired and
 * Credited are policy-terminal states.
 */
enum CoreDepositStatus: string
{
    case Outstanding = 'outstanding';
    case Returned = 'returned';
    case Expired = 'expired';
    case Credited = 'credited';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
