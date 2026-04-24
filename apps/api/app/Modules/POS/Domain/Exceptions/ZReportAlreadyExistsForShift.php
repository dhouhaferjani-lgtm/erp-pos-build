<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use Exception;

final class ZReportAlreadyExistsForShift extends Exception
{
    public static function forShift(string $shiftId, int $existingZNumber): self
    {
        return new self(
            "A Z report already exists for shift {$shiftId} (Z{$existingZNumber}). Reuse the existing report."
        );
    }
}
