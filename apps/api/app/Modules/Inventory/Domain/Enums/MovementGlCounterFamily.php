<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * The P&L counter-account family for an inventory movement.
 *
 * DirectionalVariance is resolved from the movement delta by the dedicated
 * count-correction path; it must never fall through the ordinary movement seam.
 */
enum MovementGlCounterFamily: string
{
    case Neither = 'neither';
    case Cogs = 'cogs';
    case Shrinkage = 'shrinkage';
    case DirectionalVariance = 'directional_variance';
}
