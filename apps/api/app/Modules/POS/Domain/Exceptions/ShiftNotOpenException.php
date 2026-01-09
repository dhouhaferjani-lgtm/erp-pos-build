<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Exception thrown when attempting to close a shift that is not open.
 */
class ShiftNotOpenException extends Exception
{
    public static function forShift(string $shiftId): self
    {
        return new self(
            "Cannot close shift {$shiftId}: Shift is not in OPEN status."
        );
    }

    public static function noOpenShift(string $terminalId): self
    {
        return new self(
            "No open shift found for terminal {$terminalId}."
        );
    }
}
