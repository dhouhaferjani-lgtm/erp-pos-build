<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

final class ServerFiscalAuthoringRetiredException extends RuntimeException
{
    public static function zSessionDeviceAuthority(string $terminalId, string $operation): self
    {
        return new self(sprintf(
            'Server-side %s authoring is retired for cutover terminal %s. Use device-authored Z-session fiscal events.',
            $operation,
            $terminalId,
        ));
    }
}
