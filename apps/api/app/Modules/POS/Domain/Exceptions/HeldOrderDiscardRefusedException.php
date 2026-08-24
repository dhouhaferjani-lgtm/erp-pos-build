<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use App\Modules\POS\Domain\Enums\HeldOrderStatus;

/**
 * Raised when a held order may not be discarded in its current status.
 *
 * A recalled order is the only server-side trace of what was parked and then
 * rung up, so discarding it would destroy evidence. Only a still-held or
 * expired basket may be discarded.
 */
final class HeldOrderDiscardRefusedException extends \RuntimeException
{
    public static function forStatus(string $heldOrderId, HeldOrderStatus $status): self
    {
        return new self(sprintf(
            'Held order %s cannot be discarded while it is %s.',
            $heldOrderId,
            $status->value,
        ));
    }
}
