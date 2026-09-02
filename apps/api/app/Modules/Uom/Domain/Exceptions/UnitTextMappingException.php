<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Exceptions;

use App\Modules\Uom\Domain\Enums\UnitTextMappingErrorCode;
use RuntimeException;

final class UnitTextMappingException extends RuntimeException
{
    public function __construct(
        public readonly UnitTextMappingErrorCode $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
