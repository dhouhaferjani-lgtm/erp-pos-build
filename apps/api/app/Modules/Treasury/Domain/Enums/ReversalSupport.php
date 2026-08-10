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
 *  - `CashReversal` — post the reversing entry and record one cash movement OUT.
 *    Still subject to D-5's instrument-status gate and D-17's symmetry rule (a GL
 *    leg only if the original posted one).
 *
 *    **DPA `DPA-REV2-A` (A-D2): this case does NOT mean "the AR shape".** It used
 *    to say "post the AR-shaped reversing entry (Dr AR / Cr cash)", which is now
 *    wrong: the reversing entry's ACCOUNTS come from the payment's posted ledger
 *    footprint, read by `PaymentLedgerPartitionReader`, and a single reversal may
 *    post an AR entry, a customer-advance entry, or BOTH (a mixed payment).
 *    `ReversalSupport` answers **whether** a reversal is allowed and **whether**
 *    cash moves. It never answers **which account** — the type and the ledger
 *    demonstrably disagree in three reachable shapes (X, Y, Z), and the ledger is
 *    right. This is why `Advance` and `DocumentPayment` share this case rather
 *    than needing one each.
 *  - `NoCashLeg`    — never a cash movement. D-17 STILL governs the GL leg:
 *    because there is no correct credit-side reversing shape, an original that
 *    carries a `journal_entry_id` REFUSES rather than orphaning that entry.
 *  - `Unsupported`  — fail closed with a `\DomainException`. These types are
 *    refused on DIRECTION and LANE grounds, not on account-shape grounds: a
 *    supplier payment belongs to `VendorRefundService`, a POS sale to the POS
 *    void/return lane, and a refund/reversal row is itself a negative child.
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
