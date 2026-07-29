<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a POS receipt for NF525 export.
 *
 * Immutable, primitive-typed view of POS\Domain\Receipt. Compliance never
 * imports the Eloquent model; the POS-side provider hydrates this DTO
 * (including its child line/VAT/payment collections).
 *
 * `$emit*` flags exist so the same DTO shape can drive the Tickets, Annulations,
 * and Retours sections of the JET XML without leaking POS-side filtering rules
 * back into Compliance. The provider sets them when it groups receipts.
 *
 * Refund-flow extension points (forward-compatible nullables):
 *
 * - `$exchangeGroupId` — the cryptographically-committed link between the
 *   credit-note + new-sale receipt of an exchange (refund-flow spec §3.4).
 * - `$authorizedByUserId` / `$overrideReason` / `$outOfWindow` — return-receipt
 *   audit fields populated when a manager override fires (refund-flow §3.6).
 * - `$voucherLedgerEntries` — voucher issuance + redemption rows that the
 *   refund-flow extension will emit for credit notes (issuance) and sale
 *   receipts (redemption). Empty array today.
 *
 * @see docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md §3.4, §3.6, §5.1
 */
final readonly class Nf525ReceiptData
{
    /**
     * @param  list<Nf525ReceiptLineData>  $lines
     * @param  list<Nf525ReceiptVatDetailData>  $vatDetails
     * @param  list<Nf525ReceiptPaymentData>  $payments
     * @param  list<Nf525VoucherLedgerEntryData>  $voucherLedgerEntries
     */
    public function __construct(
        public string $id,
        public string $receiptNumber,
        public string $terminalId,
        public string $postedAtIso8601,
        public int $chainSequence,
        public string $fiscalHash,
        public ?string $previousHash,
        public string $subtotal,
        public string $taxAmount,
        public string $discountAmount,
        public string $total,
        public string $currency,
        public string $cashierName,
        public ?string $customerName,
        public array $lines,
        public array $vatDetails,
        public array $payments,
        // Void shape (set when the receipt is being emitted into the Annulations section)
        public ?string $voidedAtIso8601,
        public ?string $voidedBy,
        public ?string $voidReason,
        // Return shape (set when the receipt is being emitted into the Retours section)
        public ?string $originalReceiptId,
        /** Enum value (string), e.g. "DEFECTIVE". */
        public ?string $returnReasonValue,
        // Forward-compatible — refund-flow workstream populates these:
        public ?string $exchangeGroupId = null,
        public ?string $authorizedByUserId = null,
        public ?string $overrideReason = null,
        public ?bool $outOfWindow = null,
        public array $voucherLedgerEntries = [],
        /**
         * Signed cash-rounding adjustment (`rounded − exact`) from the v3
         * SALE_RECEIPT payload, as a decimal string at the receipt currency's
         * scale. NULL on v1/v2 receipts and on every legacy mapping path;
         * '0.000' on a v3 receipt that rounded nothing. Presence — not
         * non-zero-ness — is what tells an auditor the ticket came off a
         * rounding-capable terminal, so the JET export emits the rounding
         * elements whenever this is non-null.
         */
        public ?string $cashRoundingAdjustment = null,
    ) {}
}
