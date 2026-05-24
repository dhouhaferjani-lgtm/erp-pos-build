<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a receipt payment row for NF525 export.
 *
 * Immutable view of POS\Domain\ReceiptPayment. Today only paymentType +
 * amount are emitted to the XML; the optional fields below are reserved
 * extension points for the refund-flow workstream:
 *
 * - $instrumentType (e.g. "store_voucher", "restaurant_voucher", "gift_card")
 * - $instrumentSerial (the actual voucher code / instrument identifier
 *   that the refund-flow spec §5.1 commits to the receipt's hash payload)
 *
 * These fields land as nullable so refund flow can populate them without a
 * breaking change to the contract.
 *
 * **Pass 2A.PHP.2 — canonical-only foreign-currency leg (synthesis v5 §8.B).**
 * `foreignCurrencyAmount` + `foreignCurrencyCode` are sourced from
 * `fiscal_events.payload.payments[]` and not projected to columns —
 * readable via `CanonicalPayloadReader`. For legacy receipts
 * (`fiscal_event_id IS NULL`) they default to null.
 *
 * @see docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md §5.1
 */
final readonly class Nf525ReceiptPaymentData
{
    public function __construct(
        public string $paymentType,
        public string $amount,
        public ?string $instrumentType = null,
        public ?string $instrumentSerial = null,
        // Pass 2A.PHP.2 — canonical-only fields.
        /** Foreign-currency leg amount (bcformat at the foreign scale). */
        public ?string $foreignCurrencyAmount = null,
        /** Foreign-currency leg ISO 4217 code. */
        public ?string $foreignCurrencyCode = null,
    ) {}
}
