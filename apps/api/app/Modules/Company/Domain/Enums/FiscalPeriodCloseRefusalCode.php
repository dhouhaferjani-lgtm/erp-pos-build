<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Enums;

use App\Modules\Company\Domain\Exceptions\FiscalPeriodCloseRefusedException;

/**
 * Stable API error codes for a refused MANUAL fiscal-period close (Open -> Closed).
 *
 * The mirror of {@see FiscalPeriodReopenRefusalCode}, and typed for the same reason
 * (lane Q-10 gate r1, M-1): the remedies differ in kind, so the front end branches on
 * `error.code` and never on the prose.
 *
 *  - `FISCAL_PERIOD_CLOSE_LOCKED` — the period is LOCKED. Terminal: `Locked` has no
 *    outgoing edge in the product at all (the reopen refuses it too), because lifting it
 *    means reopening its fiscal YEAR, which is program scope. Nothing to offer the user.
 *  - `FISCAL_PERIOD_CLOSE_NOT_OPEN` — the period is already Closed. Not an error the user
 *    needs to act on: the desired end state already holds, so the UI should refresh
 *    rather than surface a failure banner. (Locked gets its OWN code above, so this one
 *    always means "already Closed".)
 *  - `FISCAL_PERIOD_CLOSE_FISCAL_YEAR_CLOSED` — the period's fiscal YEAR is already
 *    closed. Its periods belong to the nightly STEP 3 lock, not to a human close; the
 *    thing that would have to be reopened (the year) is named in the code.
 *  - `FISCAL_PERIOD_CLOSE_PREDECESSOR_OPEN` — an EARLIER period of the same company is
 *    still Open. Recoverable, and the remedy is a concrete next step the other codes
 *    cannot offer: close the earlier period first. Periods settle oldest-first.
 *  - `FISCAL_PERIOD_CLOSE_NOT_ENDED` — the period has not ended yet (`end_date` is today
 *    or later). Also recoverable, but by WAITING rather than by acting, which is why it
 *    is not folded into any of the above.
 *
 * As with the reopen family, the messages on
 * {@see FiscalPeriodCloseRefusedException} are literal English rather than
 * `messages.*` keys — no fiscal-period lang namespace exists and adding one is outside
 * this lane. The CODE is the contract; the FE localises on it. Recorded as a residual.
 */
enum FiscalPeriodCloseRefusalCode: string
{
    case PeriodLocked = 'FISCAL_PERIOD_CLOSE_LOCKED';

    case PeriodNotOpen = 'FISCAL_PERIOD_CLOSE_NOT_OPEN';

    case FiscalYearClosed = 'FISCAL_PERIOD_CLOSE_FISCAL_YEAR_CLOSED';

    case PredecessorOpen = 'FISCAL_PERIOD_CLOSE_PREDECESSOR_OPEN';

    case PeriodNotEnded = 'FISCAL_PERIOD_CLOSE_NOT_ENDED';
}
