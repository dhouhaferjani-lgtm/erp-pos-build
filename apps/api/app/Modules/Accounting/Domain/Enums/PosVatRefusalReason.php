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

    /**
     * The sealed rows exist but their VAT does not add up to the receipt's own
     * `tax_amount`. Two authorities for the same number disagreeing is exactly
     * the shape W4-9 was; posting either of them would be a guess.
     */
    case SealedVatDisagreesWithReceipt = 'sealed_vat_disagrees_with_receipt';

    /**
     * The chart of accounts has no account for a system purpose this entry
     * needs. Since W4-9 a POS tender leg resolves up to three
     * (`ProductRevenue`, `VatCollected`, `SalesDiscount`); a chart missing any
     * of them cannot express the sale, and the projection refuses rather than
     * booking a partial one.
     */
    case ChartPurposeMissing = 'chart_purpose_missing';

    /** A tender or sealed VAT amount is not a plain decimal string. */
    case NonNumericAmount = 'non_numeric_amount';

    /**
     * D-1 — the sealed rows disagree with each other about which era they were
     * sealed in: some carry `discount_allocated`, some do not. `discount_allocated`
     * is the discriminator between a PRE-remise base (v1..v4, remise booked as
     * contra-revenue) and a POST-remise one (v5, remise already out of the base),
     * so a mixed set cannot be read as either. Guessing would book a wrong
     * revenue base on a receipt whose VAT the declaration still reports.
     */
    case SealedBaseEraAmbiguous = 'sealed_base_era_ambiguous';
}
