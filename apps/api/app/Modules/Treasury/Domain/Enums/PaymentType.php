<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum PaymentType: string
{
    // Standard payment applied to one or more invoices
    case DocumentPayment = 'document_payment';

    // Advance/prepayment before invoice exists (creates customer credit)
    case Advance = 'advance';

    // Refund returned to customer
    case Refund = 'refund';

    // Applying existing credit balance to pay an invoice
    case CreditApplication = 'credit_application';

    // Supplier payment (we pay them)
    case SupplierPayment = 'supplier_payment';

    // POS receipt payment (direct to revenue, no AR)
    case POS = 'pos';

    /**
     * W4R2-2 — the payment leg of a POS **refund/void** receipt, written by
     * `TreasuryReceiptBridge` with a POSITIVE amount (the sealed refund receipt
     * states the money handed back as a positive total; the direction lives in
     * the type and in the reversal journal entry, not in the sign).
     *
     * Why a distinct case rather than reusing `Refund`:
     *  - a `Refund` row is, everywhere in `PaymentRefundService`, a NEGATIVE
     *    AR-shaped child linked to its original by `original_payment_id`
     *    (:574, :1106, :1756, :2432, :2539, :2563 all read it that way). A
     *    positive, parentless POS row typed `Refund` would sit inside those
     *    predicates as a shape they were never written for;
     *  - the D-11 rule that reversal-typed rows stay invisible to the
     *    refund-total readers has the same motive, and this case keeps the POS
     *    channel equally invisible to them;
     *  - POS posts direct to revenue with no AR at all, so every AR-flavoured
     *    ruling below (`increasesReceivable`, `reversalSupport`) must answer for
     *    a POS refund the way it answers for `POS`, not the way it answers for
     *    `Refund`.
     *
     * Before W4R2-2 this leg was written as `PaymentType::POS`, whose
     * `isIncoming()` is `true` — so every POS refund was counted by the
     * dashboard's "Payments Received" tile as money that had come IN.
     *
     * DDL IS REQUIRED for this value — and this is where the `Reversal` note
     * above went stale. `2026_08_25_130300_add_enum_check_constraints_to_payments`
     * put a `chk_payments_payment_type_enum` CHECK on the column, MATERIALISED
     * from `PaymentType::cases()` at migrate time and frozen there. A tenant
     * already migrated keeps the seven-value list and would raise SQLSTATE 23514
     * on the first `pos_refund` row. This case therefore ships with a widening
     * migration (`2026_08_25_150000_widen_payments_payment_type_check_for_pos_refund`)
     * and a matching update to the batch-1 freeze test, exactly as that
     * migration's docblock instructs.
     */
    case POSRefund = 'pos_refund';

    /**
     * DPA V4 (D-1): the reversing document written by
     * `PaymentRefundService::reversePayment()` — a negative child Payment row
     * linked to the original by `original_payment_id`, carrying the NET
     * unreversed amount. Distinct from `Refund`: a payment may be refunded many
     * times but reversed at most once, and reversal-typed rows are deliberately
     * invisible to the refund-total readers (D-11).
     *
     * No DDL was needed for this value — `payments.payment_type` is a
     * `string(30)` with no CHECK constraint.
     */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::DocumentPayment => 'Invoice Payment',
            self::Advance => 'Advance Payment',
            self::Refund => 'Refund',
            self::CreditApplication => 'Credit Application',
            self::SupplierPayment => 'Supplier Payment',
            self::POS => 'POS Payment',
            self::POSRefund => 'POS Refund',
            self::Reversal => 'Payment Reversal',
        };
    }

    /**
     * Does this payment type increase what the customer owes us?
     */
    public function increasesReceivable(): bool
    {
        return match ($this) {
            self::Refund => true,  // Refund re-creates receivable if from credit
            // DPA V4 (D-2 block 2): a reversal unwinds the payment, so the
            // receivable it settled comes back — the same economic effect as a
            // refund. This arm is NOT optional: the `default => false` below
            // would answer "no" silently, with no compiler help.
            self::Reversal => true,
            // W4R2-2: `POSRefund` is DELIBERATELY left in the `default => false`
            // arm. A POS refund reverses revenue and cash directly; there is no
            // receivable to re-create. Recorded no-op, not an omission.
            default => false,
        };
    }

    /**
     * Does this payment type decrease what the customer owes us?
     */
    public function decreasesReceivable(): bool
    {
        return match ($this) {
            self::DocumentPayment => true,
            self::CreditApplication => true,
            // DPA V4 (D-2 block 3): `Reversal` is DELIBERATELY left in the
            // `default => false` arm — a recorded no-op, not an omission.
            default => false,
        };
    }

    /**
     * Does this payment type create/increase customer credit?
     */
    public function createsCredit(): bool
    {
        return match ($this) {
            self::Advance => true,
            // DPA V4 (D-2 block 4): `Reversal` is DELIBERATELY left in the
            // `default => false` arm — a reversal never mints customer credit,
            // it unwinds. Recorded no-op, not an omission.
            default => false,
        };
    }

    /**
     * Is this an incoming payment (money comes to us)?
     */
    public function isIncoming(): bool
    {
        return match ($this) {
            self::DocumentPayment => true,
            self::Advance => true,
            self::POS => true,
            self::CreditApplication => false, // No money moves, just accounting
            self::Refund => false,
            self::SupplierPayment => false,
            // W4R2-2: a POS refund hands cash BACK across the counter. This arm
            // is the whole point of the case existing — see the case docblock.
            self::POSRefund => false,
            // DPA V4 (D-2 block 5): this block is EXHAUSTIVE — omitting the
            // case would throw UnhandledMatchError. `false` keeps reversals out
            // of DashboardController's "payments received" whitelist.
            self::Reversal => false,
        };
    }

    /**
     * Is this an outgoing payment (money leaves us)?
     */
    public function isOutgoing(): bool
    {
        return match ($this) {
            self::Refund => true,
            self::SupplierPayment => true,
            // W4R2-2: money leaves the drawer on a POS refund.
            self::POSRefund => true,
            // DPA V4 (D-2 block 6): the cash branch of a reversal physically
            // moves money out. Silent-wrong if forgotten (see block 2).
            self::Reversal => true,
            default => false,
        };
    }

    /**
     * DPA V4 (D-6): can `PaymentRefundService::reversePayment()` write a
     * reversing document for this payment shape, and with which money leg?
     *
     * The `match` is deliberately EXHAUSTIVE (no `default`) so a future payment
     * type forces an explicit reversal ruling at compile time instead of
     * inheriting a shape that may be economically wrong.
     *
     * **DPA `DPA-REV2-A` (A-D2) — this method answers WHETHER, never WHICH
     * ACCOUNT.** That distinction is the whole lane, so it replaces the rule this
     * docblock used to state rather than merely dropping it.
     *
     * The old rule was: *"`createPaymentRefundJournalEntry()` posts an AR-shaped
     * entry, which is correct ONLY for `DocumentPayment`, because `Advance`
     * credits `SystemAccountPurpose::CustomerAdvance`, not AR."* The FACT is
     * still true — an advance does credit `CustomerAdvance` — but it is no longer
     * a REASON, because the reversing shape is no longer chosen from the type. It
     * is chosen from the payment's POSTED LEDGER FOOTPRINT, read by
     * `PaymentLedgerPartitionReader`.
     *
     * It had to change because the type and the ledger DISAGREE in three
     * reachable shapes, each verified against its real writer:
     *  - **X** — `payment_type = Advance` carrying AR-backed GL
     *    (`PaymentController::storeMultiple()` types on allocation exhaustion at
     *    `:1487-1490`, then posts an AR excess entry onto that same row);
     *  - **Y** — `payment_type = DocumentPayment` carrying advance-only GL (a
     *    payment allocated entirely to sales orders);
     *  - **Z** — `origin = Pos` carrying AR-backed GL on an instrument.
     *
     * Under a type-driven selector, X and Y each post the exact mirror of the
     * defect this lane exists to fix. **Do not reintroduce a `payment_type`
     * branch as a SHAPE decision anywhere.**
     *
     * What each case still decides — reversibility and the money leg only:
     *  - `DocumentPayment`, `Advance` — reversible with a cash leg. They are the
     *    same BEHAVIOUR (the partition, not the type, picks the accounts), which
     *    is why `Advance` maps to the existing `CashReversal` rather than
     *    gaining a fourth `ReversalSupport` case;
     *  - `CreditApplication` — reversible, never a cash movement;
     *  - `SupplierPayment` — wrong direction AND wrong accounts
     *    (`VendorRefundService` owns supplier refunds);
     *  - `POS` — direct-to-revenue with no AR at all (the POS void/refund lane
     *    owns it);
     *  - `Refund`/`Reversal` — themselves negative rows; reversing one is refused
     *    outright (the symmetric "refund a reversal" hole is closed by the
     *    non-positive-amount guard in `PaymentRefundService`).
     */
    public function reversalSupport(): ReversalSupport
    {
        return match ($this) {
            self::DocumentPayment => ReversalSupport::CashReversal,
            // DPA-REV2-A (A7): an advance reverses through the SAME behaviour as
            // a document payment. The accounts come from the ledger partition.
            self::Advance => ReversalSupport::CashReversal,
            self::CreditApplication => ReversalSupport::NoCashLeg,
            self::SupplierPayment => ReversalSupport::Unsupported,
            self::POS => ReversalSupport::Unsupported,
            // W4R2-2: same ruling as `POS` and for the same reason — direct to
            // revenue, no AR — reached through the POS void/refund lane only.
            self::POSRefund => ReversalSupport::Unsupported,
            self::Refund => ReversalSupport::Unsupported,
            self::Reversal => ReversalSupport::Unsupported,
        };
    }
}
