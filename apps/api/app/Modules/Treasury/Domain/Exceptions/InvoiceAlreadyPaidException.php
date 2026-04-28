<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when close-with-tolerance is attempted against an invoice that is
 * already settled (status Paid or balance_due == 0).
 */
final class InvoiceAlreadyPaidException extends RuntimeException
{
    public function __construct(public readonly string $invoiceId)
    {
        parent::__construct("Invoice {$invoiceId} is already settled; close-with-tolerance is a no-op.");
    }
}
