<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * Why a POS revenue/VAT GL projection was REFUSED (W4-9).
 *
 * Rule 9 — no magic strings: every refusal carries one of these, and the
 * value is what lands in logs, alerts and the census command's output, so a
 * deploy check can grep for a stable token rather than an English sentence.
 */
enum PosVatRefusalReason: string
{
    /**
     * The receipt has NO sealed `pos_receipt_vat_details` rows. The sale's
     * output VAT is unknowable from the ledger side, and it must NEVER be
     * recomputed from line items — the sealed breakdown is the fiscal fact.
     */
    case MissingSealedVatDetails = 'missing_sealed_vat_details';

    /**
     * The sealed VAT total exceeds the money actually retained on the tender
     * legs, so at least one revenue credit would have to be negative.
     */
    case VatExceedsTender = 'vat_exceeds_tender';

    /**
     * net + Σ VAT != the leg's tender amount. Posting it would put an
     * unbalanced (or silently wrong) entry into the chain.
     */
    case SplitDoesNotReconcile = 'split_does_not_reconcile';

    /** A tender or sealed VAT amount is not a plain decimal string. */
    case NonNumericAmount = 'non_numeric_amount';
}
