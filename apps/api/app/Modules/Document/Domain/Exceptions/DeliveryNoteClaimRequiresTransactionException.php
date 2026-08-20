<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use RuntimeException;

/**
 * A programming error — a claim was attempted without the caller transaction the protocol
 * requires. Like its sibling DeliveryNoteClaimNotFinalisedException this is an internal
 * integrity alarm, not a customer-data refusal, so it must not render as a routine 422
 * through the generic DomainException handlers. (M5-terminal treasury F-7.)
 */
final class DeliveryNoteClaimRequiresTransactionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Delivery-note billing claims require an open caller transaction.');
    }
}
