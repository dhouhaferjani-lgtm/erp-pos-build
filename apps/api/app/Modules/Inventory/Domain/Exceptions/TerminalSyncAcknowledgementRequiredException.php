<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

final class TerminalSyncAcknowledgementRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Terminal sync risk must be acknowledged before finalizing this count.');
    }
}
