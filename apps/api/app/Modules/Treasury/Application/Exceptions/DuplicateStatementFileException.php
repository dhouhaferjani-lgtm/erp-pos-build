<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Exceptions;

use DomainException;

final class DuplicateStatementFileException extends DomainException
{
    public function __construct(public readonly string $statementId)
    {
        parent::__construct("This statement file was already imported as {$statementId}.");
    }
}
