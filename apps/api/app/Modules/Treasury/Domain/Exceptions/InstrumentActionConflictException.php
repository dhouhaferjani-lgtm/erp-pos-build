<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use DomainException;

final class InstrumentActionConflictException extends DomainException
{
    public function __construct(public readonly string $actionKey)
    {
        parent::__construct(
            "Instrument action {$actionKey} was replayed with different semantic inputs.",
        );
    }
}
