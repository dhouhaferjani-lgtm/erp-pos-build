<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;
use Throwable;

final class DeliveryNoteAlreadyClaimedException extends DomainException
{
    public function __construct(
        public readonly string $deliveryNoteId,
        ?Throwable $previous = null,
        public readonly string $deliveryNoteNumber = '',
    ) {
        parent::__construct('Delivery note '.$deliveryNoteId.' has already been claimed for billing.', 0, $previous);
    }
}
