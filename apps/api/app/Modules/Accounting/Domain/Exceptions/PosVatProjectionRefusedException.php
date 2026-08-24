<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Accounting\Domain\Enums\PosVatRefusalReason;
use RuntimeException;

/**
 * The POS revenue/VAT GL projection REFUSED to post (W4-9).
 *
 * Every arm is a fail-CLOSED refusal, never a silent fallback. Before W4-9 the
 * POS booked the whole gross tender to `70x` and posted no output-VAT leg at
 * all, so the books and the DGI declaration disagreed from receipt #1 while the
 * trial balance still closed — the defect had no observable symptom. The only
 * defence against that class of bug is to make "I cannot express this sale
 * correctly" LOUD: thrown from inside the bridge's `DB::transaction()`, it
 * rolls the projection back and Horizon retries, rather than acknowledging a
 * wrong entry.
 *
 * Typed (not a bare RuntimeException) so a caller and a test can assert the
 * REASON rather than string-matching a message.
 */
final class PosVatProjectionRefusedException extends RuntimeException
{
    private function __construct(
        public readonly PosVatRefusalReason $reason,
        public readonly string $receiptId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missingSealedVatDetails(string $receiptId): self
    {
        return new self(
            PosVatRefusalReason::MissingSealedVatDetails,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s — the receipt carries no sealed pos_receipt_vat_details '
                .'rows, so the output VAT for this sale cannot be posted. It must never be recomputed from the '
                .'line items: the sealed breakdown is the fiscal fact the receipt hash and the VAT declaration '
                .'both read.',
                PosVatRefusalReason::MissingSealedVatDetails->value,
                $receiptId,
            ),
        );
    }

    /**
     * @param  numeric-string  $vatTotal
     * @param  numeric-string  $tenderTotal
     */
    public static function vatExceedsTender(string $receiptId, string $vatTotal, string $tenderTotal): self
    {
        return new self(
            PosVatRefusalReason::VatExceedsTender,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:vat=%s:tender=%s — the sealed VAT total exceeds the '
                .'retained tender, so at least one revenue credit would be negative.',
                PosVatRefusalReason::VatExceedsTender->value,
                $receiptId,
                $vatTotal,
                $tenderTotal,
            ),
        );
    }

    /**
     * @param  numeric-string  $net
     * @param  numeric-string  $vat
     * @param  numeric-string  $tender
     */
    public static function splitDoesNotReconcile(
        string $receiptId,
        string $net,
        string $vat,
        string $tender,
    ): self {
        return new self(
            PosVatRefusalReason::SplitDoesNotReconcile,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:net=%s:vat=%s:tender=%s — net + VAT must equal the '
                .'tender leg exactly at the receipt currency scale.',
                PosVatRefusalReason::SplitDoesNotReconcile->value,
                $receiptId,
                $net,
                $vat,
                $tender,
            ),
        );
    }

    public static function nonNumericAmount(string $receiptId, string $field, string $value): self
    {
        return new self(
            PosVatRefusalReason::NonNumericAmount,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:field=%s:value=%s — money must be a plain decimal string.',
                PosVatRefusalReason::NonNumericAmount->value,
                $receiptId,
                $field,
                $value,
            ),
        );
    }
}
