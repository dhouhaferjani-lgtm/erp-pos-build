<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

/**
 * Exception thrown when attempting to open a shift while one is already open.
 */
class ShiftAlreadyOpenException extends Exception
{
    public static function forTerminal(string $terminalId): self
    {
        return new self(
            "Cannot open shift: Terminal {$terminalId} already has an open shift. Close the existing shift first."
        );
    }
}
