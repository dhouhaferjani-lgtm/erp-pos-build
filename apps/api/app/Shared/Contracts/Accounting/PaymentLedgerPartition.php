<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

/**
 * DPA `DPA-REV2-A` (A-D4) — how much of a payment's POSTED general-ledger
 * footprint was booked as a RECEIVABLE and how much as a CUSTOMER ADVANCE.
 *
 * This is the lane's **sole GL-shape selector** (A-D2). `payment_type`,
 * `ReversalSupport` and `PaymentOrigin` answer *whether* a payment may be
 * reversed and whether cash moves; **none of them ever answers which account**.
 * Three reachable shapes prove why:
 *
 *  - **X** — `payment_type = Advance` carrying AR-backed GL
 *    (`PaymentController::storeMultiple()` types on allocation exhaustion, then
 *    posts an AR excess entry onto that same row);
 *  - **Y** — `payment_type = DocumentPayment` carrying advance-only GL (a
 *    payment allocated entirely to sales orders);
 *  - **Z** — `origin = Pos` carrying AR-backed GL on an instrument (the POS
 *    ACCOUNT_PAYMENT bridge).
 *
 * In every one of them the type/origin and the ledger disagree, and the ledger
 * is right.
 *
 * Both figures are `numeric-string` at the entity currency's scale. They are
 * CREDIT totals on the original payment's entries — the sides a receipt posts —
 * so a reversal debits them back.
 */
final readonly class PaymentLedgerPartition
{
    /**
     * @param  numeric-string  $arBacked  credit total on CustomerReceivable
     * @param  numeric-string  $advanceBacked  credit total on CustomerAdvance
     */
    public function __construct(
        public string $arBacked,
        public string $advanceBacked,
        public int $scale,
    ) {}

    /**
     * No POSTED AR/advance footprint keyed to this payment at all.
     *
     * The legitimate case is a pure POS **sale receipt**, which books revenue
     * directly (`source_type='pos_receipt'`, keyed on the RECEIPT), so nothing
     * matches `('customer_payment'|'advance', source_id = payment.id)`.
     */
    public function isEmpty(): bool
    {
        return bccomp($this->arBacked, '0', $this->scale) === 0
            && bccomp($this->advanceBacked, '0', $this->scale) === 0;
    }

    /** @return numeric-string */
    public function total(): string
    {
        /** @var numeric-string $total */
        $total = bcadd($this->arBacked, $this->advanceBacked, $this->scale);

        return $total;
    }
}
