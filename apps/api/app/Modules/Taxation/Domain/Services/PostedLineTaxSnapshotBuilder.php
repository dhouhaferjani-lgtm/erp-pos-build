<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Taxation\Domain\DTOs\CalculatedTax;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Builds a {@see TaxCalculationResult} from a document's OWN PERSISTED line tax
 * amounts, so the declaration can be fed the figures the GENERAL LEDGER actually
 * booked rather than a fresh recomputation.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS (B-19 fix round 1, orchestrator ruling)
 * ---------------------------------------------------------------------------
 * The purchase side has TWO arithmetic conventions for the same number, and they
 * disagree:
 *
 *   - `CreateSupplierInvoiceService` computes each line's VAT with
 *     `CurrencyScale::bcround($lineTaxHp, $scale)` — HALF-UP, PER LINE — and
 *     persists it as `document_lines.tax_amount` /
 *     `document_lines.recoverable_tax_amount`. `SupplierInvoicePostingService`
 *     then debits Σ `recoverable_tax_amount` to the VAT-deductible account
 *     (4456), and `SupplierCreditNotePostingService` credits the same sum back.
 *     That is the amount in the ledger.
 *   - `TaxCalculationService::calculateDocumentTaxes()` accumulates per-line tax
 *     at `scale+1` and TRUNCATES ONCE PER RATE BUCKET
 *     (`CurrencyScale::bcformat`). That is a different number.
 *
 * Live case on the demo tenant (`SI-2026-0008`, `SI-2026-0020`):
 * `10 × 12.601 = 126.010`, `× 19% = 23.9419` → ledger `23.942`, engine `23.941`.
 *
 * ORCHESTRATOR RULING: **the declaration follows the ledger.** 23.942 is both the
 * arithmetically correct rounding of 23.9419 and the amount actually debited to
 * 4456; a declared figure that cannot be tied to the 4456 movement is an
 * unexplainable reconciliation break at audit. So the snapshot is DERIVED from
 * the persisted line amounts, never recomputed.
 *
 * This is an AGGREGATOR, not a second tax engine: it applies no rate to any base,
 * performs no rounding decision of its own, and invents nothing. It groups
 * already-computed, already-persisted, already-posted per-line amounts by rate and
 * sums them. The result is handed to the SAME writer every other arm uses
 * ({@see TaxCalculationService::snapshotTaxDetails()}), so a supplier-invoice row
 * is structurally identical to an invoice row.
 *
 * ---------------------------------------------------------------------------
 * `recoverable_tax_amount`, NOT `tax_amount` (treasury gate F5 / addendum C)
 * ---------------------------------------------------------------------------
 * The bucket's declared VAT is Σ `recoverable_tax_amount`, because that is the
 * leg the GL posts to 4456 — not Σ `tax_amount`, which also contains any
 * non-recoverable share the ledger capitalised into inventory or charge.
 * `snapshotTaxDetails()` does not persist an `is_recoverable` flag and
 * `EloquentVatDataRepository` re-derives recoverability from `tax_configurations`
 * with `COALESCE(tc.is_recoverable, true)`, so declaring the gross line tax would
 * claim VAT the ledger never made deductible. Today the two are always equal
 * (`CreateSupplierInvoiceService` hardcodes `tax_recoverable => true`,
 * `non_recoverable_tax_amount => '0.000'`), and {@see divergences()} REFUSES the
 * document the moment they stop being equal — so the partial-recoverability lane,
 * when it is built, cannot silently over-claim.
 *
 * ---------------------------------------------------------------------------
 * NO STAMP ROW, BY CONSTRUCTION (treasury gate F9)
 * ---------------------------------------------------------------------------
 * This builder derives LINE tax only and can never emit an
 * `is_stamp_duty = true` row. That is deliberate protection, not an omission:
 * `TaxConfiguration::scopeForDocumentType()` matches any configuration whose
 * `applicable_document_types` is an EMPTY ARRAY, so a tenant-configured stamp row
 * shaped that way would make the engine attach a stamp to a purchase document —
 * and `TunisiaVatStrategy::getSpecialLineItems()` counts stamp rows with no
 * document-type filter, which would drop purchase timbre into `stamp_duty_count`
 * / `stamp_duty_total` (the SALES timbre collected for the state; per PCG-TN a
 * purchase timbre is a non-recoverable class-6 charge that must never enter that
 * line). Deriving from lines closes that path on the purchase side.
 */
final class PostedLineTaxSnapshotBuilder
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Resolve the document's monetary scale.
     *
     * Copies {@see TaxCalculationService::scaleFor()} EXACTLY, including the
     * empty-string guard: an empty currency must never be handed to the resolver,
     * because the ISO 4217 map would silently answer the default scale 2 instead
     * of the company's true scale (treasury gate M-2).
     */
    public function scaleFor(Document $document): int
    {
        $currency = (string) ($document->currency ?? '');

        if ($currency !== '') {
            return $this->scaleResolver->getScale($currency);
        }

        return $this->scaleResolver->getScaleSafe(null, 3);
    }

    /**
     * Group the document's persisted lines by their stored `tax_rate` and sum the
     * amounts the ledger booked.
     *
     * Per bucket: base = Σ `line_total` (the persisted net line the GL accrued),
     * VAT = Σ `recoverable_tax_amount` (the 4456 leg).
     */
    public function build(Document $document): TaxCalculationResult
    {
        $scale = $this->scaleFor($document);

        /** @var numeric-string $subtotal */
        $subtotal = '0';
        /** @var array<string, array{base: numeric-string, tax: numeric-string}> $buckets */
        $buckets = [];

        /** @var DocumentLine $line */
        foreach ($document->lines as $line) {
            /** @var numeric-string $lineTotal */
            $lineTotal = (string) ($line->line_total ?? '0');
            $subtotal = bcadd($subtotal, $lineTotal, $scale);

            $rateStr = $line->tax_rate !== null ? (string) $line->tax_rate : '';
            if ($rateStr === '') {
                // No rate was ever set on this line — it is not a taxable or
                // exempt supply the declaration can classify. It still counts
                // toward the subtotal (so the header tie-check stays honest),
                // but it contributes no rate bucket. `divergences()` refuses a
                // document whose lines are ALL rate-less rather than writing
                // zero rows forever (treasury gate F6).
                continue;
            }

            if (! isset($buckets[$rateStr])) {
                $buckets[$rateStr] = ['base' => '0', 'tax' => '0'];
            }

            /** @var numeric-string $recoverable */
            $recoverable = (string) ($line->recoverable_tax_amount ?? '0');
            $buckets[$rateStr]['base'] = bcadd($buckets[$rateStr]['base'], $lineTotal, $scale);
            $buckets[$rateStr]['tax'] = bcadd($buckets[$rateStr]['tax'], $recoverable, $scale);
        }

        ksort($buckets);

        $taxes = [];
        /** @var numeric-string $lineItemsTaxTotal */
        $lineItemsTaxTotal = '0';
        $sequence = 0;

        foreach ($buckets as $bucketKey => $bucket) {
            // PHP coerces a numeric-looking array key to int, so a rate of "19"
            // would come back as int 19 here. Re-cast before it reaches the DTO.
            $rateStr = (string) $bucketKey;
            $sequence++;
            $lineItemsTaxTotal = bcadd($lineItemsTaxTotal, $bucket['tax'], $scale);

            $taxes[] = new CalculatedTax(
                configurationId: '',
                // Same code/name shape TaxCalculationService's UNCONFIGURED branch
                // produces for these documents (a purchase document is
                // `fiscal_category = NonFiscal`, which the seeded TN rate rows
                // never list), so the persisted row is recognisable as the same
                // kind of row it was before the derivation changed.
                code: 'UNCONFIGURED',
                name: "VAT {$rateStr}%",
                type: TaxType::Percentage,
                rate: $rateStr,
                fixedAmount: null,
                base: $bucket['base'],
                amount: $bucket['tax'],
                sequenceOrder: $sequence,
                isStampDuty: false,
                // The amount IS the recoverable share by construction (it is Σ
                // recoverable_tax_amount), so the flag is true by definition.
                // Note snapshotTaxDetails() does not persist it — the repository
                // re-derives recoverability from tax_configurations — which is
                // why divergences() check 4 refuses any document where the
                // recoverable and gross line VAT disagree.
                isRecoverable: true,
                appliesTo: TaxApplicationLevel::LineItems,
            );
        }

        return new TaxCalculationResult(
            taxes: $taxes,
            subtotal: $subtotal,
            lineItemsTaxTotal: $lineItemsTaxTotal,
            // Never derived from lines — see the class docblock (F9).
            documentTaxTotal: '0',
            totalTax: $lineItemsTaxTotal,
            total: bcadd($subtotal, $lineItemsTaxTotal, $scale),
        );
    }

    /**
     * Every reason this derivation must NOT be declared, or an empty list when it
     * ties to the document header and to the ledger.
     *
     * A caller that writes (the posting services) throws on a non-empty list; a
     * caller that reports (the backfill command) skips and prints it. Same
     * question, one implementation, so the live writer and the admin backfill can
     * never take opposite positions on the same document — which is exactly the
     * asymmetry both gates flagged.
     *
     * @return list<string>
     */
    public function divergences(Document $document, TaxCalculationResult $derived): array
    {
        $scale = $this->scaleFor($document);

        /** @var numeric-string $storedSubtotal */
        $storedSubtotal = (string) ($document->subtotal ?? '0');
        /** @var numeric-string $storedLineTax */
        $storedLineTax = (string) ($document->line_tax_amount ?? '0');
        /** @var numeric-string $storedStampDuty */
        $storedStampDuty = (string) ($document->stamp_duty_amount ?? '0');
        /** @var numeric-string $storedTaxAmount */
        $storedTaxAmount = (string) ($document->tax_amount ?? '0');
        /** @var numeric-string $derivedSubtotal */
        $derivedSubtotal = $derived->subtotal;
        /** @var numeric-string $derivedLineTax */
        $derivedLineTax = $derived->lineItemsTaxTotal;

        $reasons = [];

        // DELIBERATELY NOT CHECKED: `subtotal + tax_amount == total`.
        //
        // The backfill's invoice/credit-note leg applies that test (its N1
        // guard), but it is NOT a declaration invariant and it has a documented,
        // legitimate counter-example on this very arm: a supplier credit note
        // carries the purchase timbre in `tax_amount` while `total` deliberately
        // EXCLUDES it, because Phase 1 does not reverse the timbre
        // (`SupplierCreditNotePostingService` posts no PurchaseStampDuty leg —
        // pinned by SupplierCreditNoteGlTest::test_timbre_is_not_reversed, e.g.
        // subtotal 20.000 / tax_amount 4.400 / total 23.800). `total` is a
        // PAYABLE figure; the declaration reads base and VAT and never reads it.
        // Checking it here would refuse a correct document over a number the
        // declaration does not use. Check 1 below binds the part that DOES
        // matter — the header's VAT decomposition.

        // 1. The header's own decomposition must hold, so that comparing the
        //    derived VAT against `line_tax_amount` in (3) is equivalent to
        //    comparing it against `tax_amount` net of the stamp.
        if (bccomp(bcadd($storedLineTax, $storedStampDuty, $scale), $storedTaxAmount, $scale) !== 0) {
            $reasons[] = sprintf(
                'stored line_tax_amount %s + stamp_duty_amount %s != stored tax_amount %s',
                $storedLineTax,
                $storedStampDuty,
                $storedTaxAmount,
            );
        }

        // 2. The lines must reconstruct the stored subtotal.
        if (bccomp($derivedSubtotal, $storedSubtotal, $scale) !== 0) {
            $reasons[] = sprintf(
                'lines sum to %s but the stored subtotal is %s',
                $derivedSubtotal,
                $storedSubtotal,
            );
        }

        // 3. The DEDUCTIBLE sum (Σ recoverable_tax_amount, the 4456 leg) must
        //    equal the document's line VAT. Today these are always equal; the
        //    day a non-recoverable share exists they will not be, and the
        //    declaration must refuse rather than claim VAT the ledger
        //    capitalised (treasury gate F5). Combined with check 1 this IS the
        //    "derived sum ties to the document's tax_amount" assertion, stated
        //    against the only decomposition that is declarable: tax_amount minus
        //    the stamp, which is never VAT.
        if (bccomp($derivedLineTax, $storedLineTax, $scale) !== 0) {
            $reasons[] = sprintf(
                'deductible line VAT (Σ recoverable_tax_amount) %s != stored line_tax_amount %s '
                .'— the non-recoverable share is not declarable',
                $derivedLineTax,
                $storedLineTax,
            );
        }

        // 4. VAT is recorded on the header but no line says at WHICH RATE, so
        //    there is no bucket to declare it in and the amount would silently
        //    vanish from the declaration while sitting in 4456. Refuse.
        //
        //    Deliberately NOT triggered by a rate-less document that records NO
        //    VAT: a purchase from a non-registered supplier is a legitimate,
        //    postable, zero-deduction document — see hasNothingToDeclare().
        //    Not reachable through CreateSupplierInvoiceService at all (vat_rate
        //    is a required field); this is the import / legacy-data case.
        if ($derived->taxes === []
            && $document->lines->isNotEmpty()
            && bccomp($storedLineTax, '0', $scale) !== 0
        ) {
            $reasons[] = sprintf(
                'the header records %s of line VAT but no line carries a tax_rate — there is no rate bucket to declare it in',
                $storedLineTax,
            );
        }

        if ($document->lines->isEmpty()) {
            $reasons[] = 'no document lines — there is no evidence to derive a VAT base or rate from';
        }

        return $reasons;
    }

    /**
     * True when the document is declarable but simply carries no VAT to declare
     * — a purchase from a non-registered supplier, or a wholly rate-less legacy
     * document with a zero VAT header.
     *
     * The writer treats this as a legitimate no-op. The BACKFILL must treat it as
     * its own census bucket rather than counting it as "snapshotted": writing
     * zero rows leaves the document with no `document_tax_details` row, so it
     * re-enters the leg's scope on every subsequent run and would otherwise be
     * reported as freshly written forever (treasury gate F6).
     */
    public function hasNothingToDeclare(TaxCalculationResult $derived): bool
    {
        return $derived->taxes === [];
    }
}
