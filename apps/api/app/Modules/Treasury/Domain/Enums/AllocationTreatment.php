<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

/**
 * How the GL must book money allocated to a document (N-6).
 *
 * The pre-N-6 code decided this by document TYPE — `SalesOrder ⇒ advance,
 * everything else ⇒ Cr 411`. That is wrong on the axis that matters: what
 * decides the booking is whether a RECEIVABLE EXISTS YET, which is a question
 * about POSTING, not about type. A confirmed invoice has no 411 debit standing
 * against it (posting is what creates receivable + revenue + VAT), so crediting
 * 411 for a payment on it drove the customer's receivable negative and recorded
 * revenue that was never recognised.
 */
enum AllocationTreatment: string
{
    /**
     * The document carries a posted receivable: Cr 411 (the money settles it).
     */
    case ReceivableClearing = 'receivable_clearing';

    /**
     * No receivable exists yet: Cr 419 customer advance (a liability — we owe
     * the customer goods or an invoice). Cleared to 411 at posting.
     */
    case Prepayment = 'prepayment';
}
