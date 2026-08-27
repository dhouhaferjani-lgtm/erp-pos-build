<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

use App\Modules\Treasury\Domain\Exceptions\CashTenderInvariantViolationException;

/**
 * Stable API error codes for a refused payment-method write that would leave
 * `payment_methods.is_cash_tender` incoherent with the method's `code`.
 *
 * # Why the codes are typed (Session D final review, finding I-1)
 *
 * `is_cash_tender` is THE cash-ness predicate — the shared repository rule
 * (`Treasury\Application\Services\TenderRepositoryResolver`),
 * the fiscal bridge, the device checkout resolver
 * (`apps/pos/src/lib/payment/cashMethods.ts`) and, since I-1, the server's
 * expected-cash derivation (`POS\Application\Services\ShiftExpectedCashService`)
 * all read that ONE column. Before I-1 the write guard was one-way: it refused
 * `is_cash_tender = true` on a non-canonical code but happily persisted the
 * MIRROR of that defect — a canonical `CASH` row with the flag false. Such a
 * row was classified in OPPOSITE directions by the two families of consumer
 * (flag-readers said "not cash", code-readers said "cash"), which can put a
 * repository movement and a certified expected-cash figure on different tenders.
 *
 * The two remedies differ in kind, so a front end must be able to branch on the
 * code rather than on the prose:
 *
 *  - `PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE` — the write asks
 *    for `is_cash_tender = true` on a code that is not the canonical `CASH`.
 *    Remedy: clear the flag, or rename the method to `CASH` (only ONE method per
 *    company may hold that code — `unique(company_id, code)`). This is the
 *    original one-way guard, unchanged in semantics: the comparison is EXACT
 *    (case-sensitive), because a mixed-case brownfield row the A1 backfill had to
 *    skip must NOT become flaggable by the back door.
 *  - `PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED` — the write would leave a
 *    method whose code IS canonical cash (compared case-INsensitively, so the
 *    mixed-case collision losers of the A1 backfill are caught too) with
 *    `is_cash_tender = false`. Remedy: set the flag (available when the stored
 *    code is exactly `CASH`), or rename the method to something that is not a
 *    cash code at all (the remedy for a mixed-case collision loser, which cannot
 *    be flagged because the canonical row already owns the code).
 *
 * The case asymmetry is deliberate and is the whole point: the flag may only be
 * SET on the exact canonical code, but a code that merely LOOKS like cash to any
 * consumer that ever compared case-insensitively may not be left unflagged and
 * ambiguous. Both directions therefore converge on one reading of a row.
 *
 * @see CashTenderInvariantViolationException the carrier
 */
enum CashTenderInvariantRefusalCode: string
{
    case FlagOnNonCanonicalCode = 'PAYMENT_METHOD_CASH_TENDER_FLAG_ON_NON_CANONICAL_CODE';

    case CanonicalCodeNotFlagged = 'PAYMENT_METHOD_CANONICAL_CASH_CODE_NOT_FLAGGED';
}
