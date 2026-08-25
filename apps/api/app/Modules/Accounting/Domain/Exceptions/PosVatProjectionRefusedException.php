<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Accounting\Domain\Enums\PosVatRefusalReason;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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
     * `$tenderTotal` is the base the VAT may legitimately sit on — the tender
     * PLUS any transaction discount, i.e. the gross the sale was struck at,
     * which is the base the device sealed the VAT on. Comparing against the
     * bare tender refused every 100 %-off comp (gate r2, R2-1).
     *
     * @param  numeric-string  $vatTotal
     * @param  numeric-string  $tenderTotal
     */
    public static function vatExceedsTender(string $receiptId, string $vatTotal, string $tenderTotal): self
    {
        return new self(
            PosVatRefusalReason::VatExceedsTender,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:vat=%s:gross_base=%s — the sealed VAT total exceeds '
                .'the tender plus the transaction discount, so at least one revenue credit would be negative.',
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

    /**
     * @param  numeric-string  $sealedVat
     * @param  numeric-string  $receiptTaxAmount
     */
    public static function sealedVatDisagreesWithReceipt(
        string $receiptId,
        string $sealedVat,
        string $receiptTaxAmount,
    ): self {
        return new self(
            PosVatRefusalReason::SealedVatDisagreesWithReceipt,
            $receiptId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:sealed_vat=%s:receipt_tax_amount=%s — the sealed '
                .'pos_receipt_vat_details rows and pos_receipts.tax_amount disagree; the ledger will not pick '
                .'a winner.',
                PosVatRefusalReason::SealedVatDisagreesWithReceipt->value,
                $receiptId,
                $sealedVat,
                $receiptTaxAmount,
            ),
        );
    }

    /**
     * Named preflight refusal for an unprovisionable chart (treasury gate I-2).
     *
     * Before W4-9 a POS tender leg resolved ONE purpose; it now resolves up to
     * three, so the blast radius of a missing account grew from "one wrong
     * revenue line" to "the whole receipt is lost — payment, cash movement and
     * GL alike — on a queue job that retries forever". `getAccountByPurpose()`
     * raises a bare `RuntimeException` from the depths of the writer; this says
     * WHICH purpose is missing, in the same typed shape as every other refusal
     * on this path, so an operator reading a failed job knows what to provision.
     */
    public static function chartPurposeMissing(string $subjectId, SystemAccountPurpose $purpose): self
    {
        return new self(
            PosVatRefusalReason::ChartPurposeMissing,
            $subjectId,
            sprintf(
                'pos_vat_projection_refused:%s:receipt=%s:purpose=%s — the chart of accounts has no account '
                .'carrying this system purpose, so the POS revenue/VAT decomposition cannot be posted. Provision '
                .'it (Settings → Accounting → Chart of accounts) and the projection will retry cleanly; '
                .'`php artisan pos:census-vat-legs` reports the same gap with exit code 2.',
                PosVatRefusalReason::ChartPurposeMissing->value,
                $subjectId,
                $purpose->value,
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
