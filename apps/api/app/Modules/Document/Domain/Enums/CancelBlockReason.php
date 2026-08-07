<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;

/**
 * Why a document's Cancel action is unavailable, for the `can-cancel` read model.
 *
 * R2-F1 / GL gate I-3. Before this, `canCancelInvoice()` returned a bare boolean
 * and knew nothing about accounting periods, so the front end would render a live
 * Cancel button for an invoice in a FILED period — a permanently dead end, since
 * a filed declaration can never be reopened. The endpoint now reports WHY, so the
 * UI can disable the button and say "issue a credit note instead".
 *
 * Period refusals are NOT duplicated here: `cancellationBlockReason()` returns the
 * code straight from {@see DocumentPeriodLockInterface::cancellationRefusalCode()},
 * which is the same value the 422 carries. This enum covers only the reasons the
 * Document module owns itself.
 */
enum CancelBlockReason: string
{
    case NotAnInvoice = 'NOT_AN_INVOICE';
    case HasPayments = 'DOCUMENT_HAS_PAYMENTS';
    case AlreadyCancelled = 'DOCUMENT_ALREADY_CANCELLED';
    case StatusNotCancellable = 'DOCUMENT_STATUS_NOT_CANCELLABLE';
}
