<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enums;

/**
 * Stable API error codes for a return note refused because the period covering
 * its `document_date` is no longer open.
 *
 * Plan CF CF-D3. Three codes, not one, because the remedies differ in kind and
 * the modal has to say which one applies:
 *
 *  - `RETURN_PERIOD_CLOSED` — a `vat_periods` row is CLOSED. An accountant can
 *    still reopen it (`VatPeriodManagementService::reopenPeriod()`), so the UI can
 *    offer "ask your accountant to reopen ⟨label⟩".
 *  - `RETURN_PERIOD_FILED` — a `vat_periods` row is FILED. The declaration is with
 *    the tax authority and can never be undone.
 *  - `RETURN_PERIOD_LOCKED` — a `fiscal_periods` row covering the date is Closed
 *    or Locked. The books are shut for that span.
 *
 * These are DISTINCT from Taxation's `PeriodLockRefusalCode`
 * (`DOCUMENT_PERIOD_CLOSED` / `_FILED`), which refuses the CANCEL of a
 * ledger-bearing document. A cancel refusal and a return-date refusal are
 * different failures with different fixes — the cancel refusal is about the
 * invoice, this one is about the date the user typed into the modal — and the
 * front end renders them in different places: a blocking banner versus an inline
 * error on the date field. Collapsing them into one code would force the UI to
 * guess (the same argument `PeriodLockRefusalCode`'s own docblock makes).
 *
 * Both period tables are ABSENT-PERMITS. No covering row means nothing has ever
 * been closed, filed or locked for that span, so there is nothing to protect —
 * and failing closed on absence would make every return note unconfirmable on
 * every tenant that has not begun declaring, which is all of them at launch.
 * The house precedent is exact: `FiscalPeriodResolverService::isDateInClosedPeriod()`
 * ("absence of configuration is not the same as a deliberately closed period").
 */
enum ReturnPeriodRefusalCode: string
{
    case PeriodClosed = 'RETURN_PERIOD_CLOSED';

    case PeriodFiled = 'RETURN_PERIOD_FILED';

    case PeriodLocked = 'RETURN_PERIOD_LOCKED';

    /**
     * Translation key for the user-facing message (lang/{locale}/messages.php).
     */
    public function translationKey(): string
    {
        return match ($this) {
            self::PeriodClosed => 'messages.taxation.return_refused_period_closed',
            self::PeriodFiled => 'messages.taxation.return_refused_period_filed',
            self::PeriodLocked => 'messages.taxation.return_refused_period_locked',
        };
    }

    /**
     * Whether an accountant can still lift this refusal.
     *
     * FILED is the only permanent one, and the modal must not offer "ask your
     * accountant to reopen it" for a period that can never be reopened.
     */
    public function isRecoverable(): bool
    {
        return $this !== self::PeriodFiled;
    }
}
