<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a voucher-ledger entry attached to a receipt for NF525 export.
 *
 * Reserved extension point for the refund-flow workstream. Today the export
 * does not emit voucher-ledger rows (the Voucher module does not yet exist).
 * Once refund flow lands, the POS-side provider populates this DTO from the
 * `voucher_ledger` table for every redemption tender on a sale receipt and
 * every issuance ledger row on a credit-note receipt; the XML builder then
 * emits a `<VoucherLedger>` block per receipt without breaking the v1
 * snapshot for receipts that have no voucher activity.
 *
 * @see docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md §3.1, §5.1
 */
final readonly class Nf525VoucherLedgerEntryData
{
    public function __construct(
        public string $voucherId,
        public string $voucherCode,
        /**
         * Ledger event: "Issued", "Redeemed", "PartiallyRedeemed", "Voided",
         * "Expired", "Transferred", or "Reversed".
         */
        public string $event,
        /** Signed numeric string ("+10.00" or "-5.00"). */
        public string $amount,
        public ?string $glJournalEntryId,
    ) {}
}
