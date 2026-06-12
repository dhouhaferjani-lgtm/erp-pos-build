<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

use App\Enums\Vertical;

/**
 * What the POS does when a cashier tries to sell beyond the terminal
 * location's available stock (spec §4.2). Per-location stock AWARENESS is
 * unconditional — this only selects the enforcement behavior.
 */
enum PosStockPolicy: string
{
    case Block = 'block';
    case Warn = 'warn';
    case Off = 'off';

    /**
     * Made-to-order verticals (Menu module in their default set) have no
     * finished-goods stock rows — hard blocking would freeze their POS.
     */
    public static function defaultForVertical(Vertical $vertical): self
    {
        return in_array('Menu', $vertical->defaultModules(), true)
            ? self::Off
            : self::Block;
    }
}
