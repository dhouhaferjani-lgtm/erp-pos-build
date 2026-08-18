<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

final class DeliveryNoteClaimRequiresTransactionException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Delivery-note billing claims require an open caller transaction.');
    }
}
