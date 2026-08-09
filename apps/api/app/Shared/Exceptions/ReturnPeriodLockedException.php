<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\Domain\Enums\ReturnPeriodRefusalCode;
use DomainException;

/**
 * Thrown when a return note would be confirmed with a `document_date` inside a
 * period that is no longer open — a `vat_periods` row that is CLOSED or FILED, or
 * a `fiscal_periods` row that is Closed or Locked.
 *
 * Plan CF CF-D3. The guard lives in `ReturnNoteService::confirmWithin()` so the
 * manual `POST /return-notes/{id}/confirm` route and the composite
 * `POST /invoices/{id}/cancel` cannot drift apart, and it runs BEFORE
 * `receiveStockBack()` and BEFORE the chain-head `lockForUpdate()` — otherwise a
 * refusal would still hold the return-note chain head for the rest of the
 * transaction.
 *
 * A refusal is NOT a dead end. A draft return note's `document_date` is editable
 * through `PATCH /return-notes/{id}` (`ReturnNoteController::update()`, gated on
 * `isDraft()`, trigger-safe because `fiscal_status` is still DRAFT), so the
 * recovery is one PATCH into an open period followed by a confirm. That recovery
 * window is only non-empty because plan CF withdrew T14's second,
 * calendar-aligned refusal on the same `confirm()` — two guards keyed on
 * calendar-aligned boundaries would have made a boundary-crossing return
 * PERMANENTLY unconfirmable, and the goods would never re-enter stock.
 *
 * Lives in `app/Shared/Exceptions/` rather than a module: it is raised through the
 * Shared `PeriodBackdatingGuardInterface` seam, so a module-owned type here would
 * make the contract depend on a module tier (the violation
 * `DocumentPeriodLockInterface` already carries).
 */
final class ReturnPeriodLockedException extends DomainException
{
    private function __construct(
        string $message,
        public readonly ReturnPeriodRefusalCode $refusalCode,
        public readonly string $documentNumber,
        public readonly string $returnDate,
        public readonly ?string $periodLabel,
    ) {
        parent::__construct($message);
    }

    public static function forDate(
        ReturnPeriodRefusalCode $refusalCode,
        string $documentNumber,
        string $returnDate,
        ?string $periodLabel = null,
    ): self {
        return new self(
            (string) __($refusalCode->translationKey(), [
                'document' => $documentNumber,
                'date' => $returnDate,
                'period' => $periodLabel ?? $returnDate,
            ]),
            $refusalCode,
            $documentNumber,
            $returnDate,
            $periodLabel,
        );
    }
}
