<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

/**
 * DPA V4 / D-6 — how (or whether) a given `PaymentType` can be reversed by
 * `PaymentRefundService::reversePayment()`.
 *
 * The reversing document itself (the `PaymentType::Reversal` child row plus its
 * negative allocation mirrors) is written for every SUPPORTED shape. What
 * differs is the money/GL leg:
 *
 *  - `CashReversal` — post the AR-shaped reversing entry (Dr AR / Cr cash) and
 *    record one cash movement OUT. Still subject to D-5's instrument-status
 *    gate and D-17's symmetry rule (a GL leg only if the original posted one).
 *  - `NoCashLeg`    — never a cash movement. D-17 STILL governs the GL leg:
 *    because there is no correct credit-side reversing shape, an original that
 *    carries a `journal_entry_id` REFUSES rather than orphaning that entry.
 *  - `Unsupported`  — fail closed with a `\DomainException`. The AR-shaped
 *    entry `createPaymentRefundJournalEntry()` produces would be the WRONG
 *    shape for these types, and "silently zero" (today's behaviour) beats
 *    "silently wrong".
 *
 * Resolved by `PaymentType::reversalSupport()`, whose `match` is exhaustive so
 * a future payment type is a compile-time decision rather than a silent
 * default.
 */
enum ReversalSupport: string
{
    case CashReversal = 'cash_reversal';

    case NoCashLeg = 'no_cash_leg';

    case Unsupported = 'unsupported';
}
