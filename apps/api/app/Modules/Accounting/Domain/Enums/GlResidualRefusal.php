<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * Why a document's GL posting cannot be balanced, and must therefore be refused.
 *
 * The document-sourced GL paths debit AR with the HEADER `total` and credit the
 * LINES' revenue plus a RECOMPUTED line VAT. The difference between the two sides
 * is the residual. Its sign and size say whether it is bookable:
 *
 * - a POSITIVE residual is the normal shape. It carries the document-level charge
 *   a chart may define (the Tunisian timbre) and, on every chart, the per-line tax
 *   truncation bias — `groupTaxByRate()` truncates each line's tax while
 *   `TaxCalculationService` truncates once per rate bucket, and
 *   `Σ trunc(xᵢ) ≤ trunc(Σ xᵢ)`, so the header tax can exceed the GL VAT by up to
 *   one unit of the last place per line. It is booked to an absorbing account.
 * - a NEGATIVE residual is never a rounding artefact: the credit side over-runs
 *   the AR debit, which means the header understates its own lines. That is the
 *   `MTP-DOC-06` shape and it is refused.
 *
 * W-6 D1a — docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md
 */
enum GlResidualRefusal: string
{
    /**
     * Credits over-run the AR debit. Always a bug — refuse regardless of size.
     */
    case NegativeResidual = 'negative_residual';

    /**
     * A positive residual with no absorbing account configured on this chart.
     * Booking it is impossible and dropping it would seal an unbalanced entry.
     */
    case NoAbsorbingAccount = 'no_absorbing_account';

    /**
     * A positive residual larger than per-line tax truncation can explain, on a
     * chart with no document-level charge account (no `SalesStampDutyPayable`).
     *
     * REFUSED, never absorbed-with-an-alert (fiscal-pos gate ruling, 2026-08-05):
     * sweeping unexplained money into a "produits divers de gestion courante"
     * account is a silent misstatement of income, and an alert nobody reads does
     * not make it less silent.
     *
     * The cause is NOT always bad totals — it is just as likely a real
     * document-level charge (a timbre-equivalent) on a chart that does not model
     * one, which is a chart-configuration fix, so the message offers both remedies.
     */
    case ResidualExceedsRoundingTolerance = 'residual_exceeds_rounding_tolerance';

    /**
     * Defence in depth: the written legs do not sum equal after everything above
     * was satisfied. Unreachable by construction; a true bug if it ever fires.
     */
    case LegsDoNotBalance = 'legs_do_not_balance';

    public function message(): string
    {
        return match ($this) {
            self::NegativeResidual => 'The document total is less than the sum of its lines plus their tax, so the general-ledger entry cannot balance. Correct the document totals before posting.',
            self::NoAbsorbingAccount => 'The document total exceeds the sum of its lines plus their tax, and this chart of accounts has no account to absorb the difference. Assign a rounding-difference account in Settings -> Chart of Accounts.',
            self::ResidualExceedsRoundingTolerance => 'The document total exceeds the sum of its lines plus their tax by more than tax rounding can explain. If the difference is a document-level charge (for example a stamp duty), assign the account that should carry it in Settings -> Chart of Accounts; otherwise correct the document totals.',
            self::LegsDoNotBalance => 'The general-ledger entry for this document does not balance.',
        };
    }
}
