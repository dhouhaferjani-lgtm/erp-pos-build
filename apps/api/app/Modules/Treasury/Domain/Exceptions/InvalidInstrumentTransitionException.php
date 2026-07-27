<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use DomainException;

final class InvalidInstrumentTransitionException extends DomainException
{
    public function __construct(string $action, InstrumentStatus $status)
    {
        parent::__construct("Cannot {$action} an outbound instrument in {$status->value} status.");
    }
}
