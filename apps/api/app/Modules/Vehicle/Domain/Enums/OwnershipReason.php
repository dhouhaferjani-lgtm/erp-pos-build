<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Domain\Enums;

enum OwnershipReason: string
{
    case InitialRegistration = 'initial_registration';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Transfer = 'transfer';
    case TradeIn = 'trade_in';
    case FleetAssignment = 'fleet_assignment';
    case FleetReturn = 'fleet_return';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
