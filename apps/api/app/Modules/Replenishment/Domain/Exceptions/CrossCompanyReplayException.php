<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain\Exceptions;

use RuntimeException;

final class CrossCompanyReplayException extends RuntimeException
{
    public function __construct(public readonly string $clientRequestUuid)
    {
        parent::__construct('Replenishment capture UUID belongs to another company.');
    }
}
