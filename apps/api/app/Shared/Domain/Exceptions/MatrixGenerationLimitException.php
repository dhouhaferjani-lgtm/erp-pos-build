<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use RuntimeException;

final class MatrixGenerationLimitException extends RuntimeException
{
    public static function exceeded(int $requested, int $max): self
    {
        return new self("Variant matrix of {$requested} exceeds the limit of {$max}.");
    }
}
