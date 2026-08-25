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

    /**
     * C-0a0 / SPEC §2.1 rule 8 — the PAYABLE side: a posted supplier invoice
     * carries a Cr 401, and the money settles it (Dr 401 / Cr bank).
     *
     * N-6 left this OUTSIDE the classifier: `PaymentController::store()` wrote
     * `$document->type === SupplierInvoice ? null : classify($document)`, a
     * bypass that made the one path capable of paying a supplier the one path
     * the policy object never saw. Naming the treatment lets every writer call
     * the classifier unconditionally, and lets the AR-only writers refuse this
     * value explicitly instead of refusing the TYPE by hand
     * (`classifyReceivableSide()`).
     */
    case PayableSettlement = 'payable_settlement';

    /**
     * Does this treatment book the CUSTOMER (receivable) side?
     *
     * The AR-only entry points — smart allocation, multi-line, excess
     * allocation, split payment, deposit application — post Dr bank / Cr
     * 411-or-419 and increment a repository. Handing them a payable settlement
     * would move cash the wrong way against the wrong account.
     */
    public function isReceivableSide(): bool
    {
        return match ($this) {
            self::ReceivableClearing, self::Prepayment => true,
            self::PayableSettlement => false,
        };
    }
}
