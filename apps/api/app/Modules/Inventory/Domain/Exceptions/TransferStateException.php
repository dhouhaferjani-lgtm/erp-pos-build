<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\TransferStatus;
use DomainException;

/**
 * Thrown when an inventory transfer state transition is invalid
 * (e.g., trying to send a transfer that is already in_transit).
 */
class TransferStateException extends DomainException
{
    public function __construct(
        public readonly string $transferId,
        public readonly TransferStatus $currentStatus,
        public readonly string $attemptedAction,
    ) {
        parent::__construct(
            "Cannot {$attemptedAction} transfer {$transferId} in status {$currentStatus->value}"
        );
    }
}
