<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

use App\Modules\Company\Domain\Exceptions\FiscalPeriodReopenRefusedException;

/**
 * Stable API error codes for a refused fiscal-period reopen (Closed -> Open).
 *
 * FOUR codes, not one, because the remedies differ in kind and the front end has
 * to say which one applies (the same argument Taxation's `PeriodLockRefusalCode`
 * and Shared's `ReturnPeriodRefusalCode` make for their own refusals):
 *
 *  - `FISCAL_PERIOD_REOPEN_LOCKED` — the period is LOCKED. Terminal in this lane:
 *    its fiscal year is closed and lifting that is the year-level reopen, which
 *    does not exist yet. Nothing the operator can do in-product.
 *  - `FISCAL_PERIOD_REOPEN_NOT_CLOSED` — the period is already OPEN. Not an error
 *    the user needs to act on: postings are possible right now, so the UI should
 *    simply refresh rather than surface a failure banner.
 *  - `FISCAL_PERIOD_REOPEN_FISCAL_YEAR_CLOSED` — the period is Closed but its
 *    fiscal YEAR is closed. Same dead end as LOCKED, reached from a different
 *    state; kept distinct because the thing to reopen (the year) is named in it.
 *  - `FISCAL_PERIOD_REOPEN_SUCCESSOR_SETTLED` — a LATER period of the same company
 *    is already Closed or Locked. This one is recoverable in principle (settle the
 *    correction through the open period instead, or reopen the successors first,
 *    newest-first), so the UI can offer a next step the other three cannot.
 *
 * Collapsing them into one code would force the UI to parse the prose message.
 *
 * The messages themselves are still literal English on
 * {@see FiscalPeriodReopenRefusedException}
 * rather than `messages.*` translation keys — no fiscal-period lang namespace
 * exists yet and adding one is outside this fix round. The CODE is the contract;
 * the FE localises on it. Recorded as a residual by the lane report.
 */
enum FiscalPeriodReopenRefusalCode: string
{
    case PeriodLocked = 'FISCAL_PERIOD_REOPEN_LOCKED';

    case PeriodNotClosed = 'FISCAL_PERIOD_REOPEN_NOT_CLOSED';

    case FiscalYearClosed = 'FISCAL_PERIOD_REOPEN_FISCAL_YEAR_CLOSED';

    case SuccessorSettled = 'FISCAL_PERIOD_REOPEN_SUCCESSOR_SETTLED';
}
