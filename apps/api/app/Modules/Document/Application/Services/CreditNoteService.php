<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type ProratedLineSpec array{
 *     source: ?DocumentLine,
 *     quantity: numeric-string,
 *     unitPrice: numeric-string,
 *     discountAmount: numeric-string,
 *     targetNet: numeric-string,
 *     rate: numeric-string
 * }
 * @phpstan-type GroupAllocationResult array{
 *     net: numeric-string,
 *     vat: numeric-string,
 *     lines: list<ProratedLineSpec>,
 *     exact: bool
 * }
 */
class CreditNoteService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DocumentAllocationStateGuard $allocationStateGuard,
    ) {}

    /**
     * Resolve the monetary scale for a document, guarding against a blank
     * currency (which would otherwise silently resolve to the PG-default
     * scale 2 instead of the company's true scale). Mirrors
     * TaxCalculationService::scaleFor().
     */
    private function scaleFor(Document $document): int
    {
        $currency = $document->currency;

        if ($currency !== '') {
            return $this->scaleResolver->getScale($currency);
        }

        return $this->scaleResolver->getScaleSafe(null, 3);
    }

    /**
     * Recompute subtotal/tax_amount/total from the document's OWN persisted
     * lines via TaxCalculationService::calculateDocumentTaxes() -- the EXACT
     * SAME function CreditNoteController::confirm() calls -- and persist all
     * three columns.
     *
     * 2026-08-03 re-gate (B1 + B2 + B3/R1): using the identical function
     * (instead of a hand-rolled parallel accumulation loop per creation path)
     * is what GUARANTEES draft == confirm == posted byte-for-byte on every
     * path -- there is no second implementation of the money math to keep in
     * sync. Closes the line-based discount bug (B1: the old hand-rolled loop
     * ignored discount_percent/discount_amount entirely), the standalone
     * per-line-truncation divergence (B3, same class as carry-over R1), and
     * (paired with the confirm() fix) the stale-subtotal invariant break
     * (B2: subtotal + tax_amount was allowed to not equal total).
     *
     * `calculateDocumentTaxes()`'s STEP 1 honours an explicitly-supplied line
     * rate even when no TaxConfiguration row matches it (the "UNCONFIGURED"
     * fallback, TaxCalculationService.php ORCHESTRATOR RULING) -- so, unlike
     * the invoice-side `withDocumentLevelTaxes()` helper (which deliberately
     * consumes only `documentTaxTotal` to avoid an older zeroing risk),
     * consuming `totalTax` directly here is safe and is the whole point: it
     * IS confirm()'s computation, verbatim.
     */
    private function applyConfirmEquivalentTotals(Document $document, int $scale): TaxCalculationResult
    {
        $document->setRelation('lines', $document->lines()->get());

        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($document);

        // Gate C-1 (2026-08-07, Q1 lane) — `documentTaxTotal` (the credit
        // note's OWN document-level duty, e.g. STAMP_CREDIT_NOTE) and
        // `lineItemsTaxTotal` were folded into `total`/`tax_amount` here but
        // never persisted to their own columns, so
        // `AccountingService::createCreditNoteGLEntries()`'s Q1 fix
        // (`$hasStampDuty = $document->stamp_duty_amount > 0`) was inert on
        // every REAL credit note — this is the SAME split
        // `DocumentTotalsCalculator` already persists for drafts/invoices.
        $document->update([
            'subtotal' => $taxResult->subtotal,
            'line_tax_amount' => $taxResult->lineItemsTaxTotal,
            'stamp_duty_amount' => $taxResult->documentTaxTotal,
            'tax_amount' => $taxResult->totalTax,
            'total' => $taxResult->total,
        ]);

        return $taxResult;
    }

    /**
     * The invoice's remaining credit headroom, duty-EXCLUSIVE on BOTH sides
     * (RULING B, 2026-08-03 re-gate, closes D1). D1's bug: the guard compared
     * a duty-exclusive `$amount` against a remaining computed from
     * `sum(priorCreditNotes.total)`, which is duty-INCLUSIVE (each prior
     * CN's own stamp is baked into its `total`) -- so a stack of credit
     * notes' stamps compounded into an artificially shrinking headroom,
     * eventually allowing `Σ total > invoice.total` and driving
     * `balance_due` negative.
     *
     * This sums each prior credit note's OWN duty-exclusive amount
     * (`total - that CN's own documentTaxTotal`), never its duty-inclusive
     * total, so N credit notes' stamps never eat into each other's headroom.
     * Status-blind (drafts reserve headroom too -- MTP-DOC-18 depends on
     * this: a further request must be refused while an earlier one is still
     * Draft). This is a soft/advisory bound only; the HARD invariant
     * (`balance_due` can never go negative) is enforced independently, at
     * allocation time, by `allocateCreditNote()`'s clamp below -- so getting
     * this formula slightly imprecise in some exotic edge case cannot itself
     * corrupt `balance_due`.
     *
     * @param  string|null  $excludeCreditNoteId  The credit note currently
     *                                            being created, if it already has a persisted row by the time this
     *                                            guard runs (createLineBasedCreditNote() creates the document shell
     *                                            BEFORE it knows the real total, so it must exclude itself here or
     *                                            it would count against its own headroom).
     */
    private function remainingCreditHeadroom(Document $invoice, int $scale, ?string $excludeCreditNoteId = null): string
    {
        $priorCreditNotesQuery = $invoice->creditNotes();
        if ($excludeCreditNoteId !== null) {
            $priorCreditNotesQuery->where('id', '!=', $excludeCreditNoteId);
        }

        /** @var Collection<int, Document> $priorCreditNotes */
        $priorCreditNotes = $priorCreditNotesQuery->with('lines')->get();

        /** @var numeric-string $consumed */
        $consumed = '0';
        foreach ($priorCreditNotes as $priorCreditNote) {
            /** @var numeric-string $priorDuty */
            $priorDuty = $this->taxCalculationService->calculateDocumentTaxes($priorCreditNote)->documentTaxTotal;
            /** @var numeric-string $priorAmount */
            /** @phpstan-ignore-next-line argument.type */
            $priorAmount = bcsub((string) $priorCreditNote->total, $priorDuty, $scale);
            $consumed = bcadd($consumed, $priorAmount, $scale);
        }

        // precision-ok: 4 = canonical amount comparison scale, matches the
        // pre-existing (unchanged) guard scale this replaces.
        /** @phpstan-ignore-next-line argument.type */
        return bcsub($invoice->total, $consumed, 4);
    }

    /**
     * Distribute $total across $weights proportionally using the
     * largest-remainder method (Hamilton apportionment), so the allocations
     * sum to $total EXACTLY at $scale -- no lossy per-item truncation.
     * Non-positive weights get zero allocation.
     *
     * ORCHESTRATOR RULING (2026-08-03, root-cause of the 50.174 drift):
     * internal decomposition must reconstruct the requested amount exactly
     * where reachable; this is the shared primitive both the group-level
     * (across tax rates) and line-level (within a rate group) allocations
     * use.
     *
     * @param  numeric-string  $total
     * @param  list<numeric-string>  $weights
     * @return list<numeric-string>
     */
    private function largestRemainderAllocate(string $total, array $weights, int $scale): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $precision = $scale + 6;

        /** @var numeric-string $weightSum */
        $weightSum = '0';
        foreach ($weights as $w) {
            if (bccomp($w, '0', $precision) > 0) {
                $weightSum = bcadd($weightSum, $w, $precision);
            }
        }

        if (bccomp($weightSum, '0', $precision) <= 0) {
            return array_fill(0, $count, '0');
        }

        /** @var array<int, numeric-string> $bases */
        $bases = [];
        /** @var array<int, numeric-string> $remainders */
        $remainders = [];
        /** @var numeric-string $allocatedSum */
        $allocatedSum = '0';

        foreach ($weights as $i => $w) {
            if (bccomp($w, '0', $precision) <= 0) {
                $bases[$i] = '0';
                $remainders[$i] = '0';

                continue;
            }

            /** @var numeric-string $share */
            $share = bcdiv(bcmul($total, $w, $precision), $weightSum, $precision);
            /** @var numeric-string $base */
            $base = bcadd($share, '0', $scale);
            $bases[$i] = $base;
            /** @var numeric-string $remainder */
            $remainder = bcsub($share, $base, $precision);
            $remainders[$i] = $remainder;
            $allocatedSum = bcadd($allocatedSum, $base, $scale);
        }

        /** @var numeric-string $shortfall */
        $shortfall = bcsub($total, $allocatedSum, $scale);
        /** @var numeric-string $tick */
        $tick = bcdiv('1', bcpow('10', (string) $scale, 0), $scale);

        // precision-ok: 0 = an integer TICK COUNT (how many currency ticks
        // short/over), not a monetary amount -- always scale 0 regardless of currency.
        $ticksToDistribute = (int) bcdiv($shortfall, $tick, 0);

        $order = array_keys($remainders);
        if ($ticksToDistribute > 0) {
            usort($order, fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], $precision));
            for ($k = 0; $k < $ticksToDistribute; $k++) {
                $idx = $order[$k % count($order)];
                $bases[$idx] = bcadd($bases[$idx], $tick, $scale);
            }
        } elseif ($ticksToDistribute < 0) {
            usort($order, fn (int $a, int $b): int => bccomp($remainders[$a], $remainders[$b], $precision));
            for ($k = 0; $k < abs($ticksToDistribute); $k++) {
                $idx = $order[$k % count($order)];
                if (bccomp($bases[$idx], $tick, $scale) >= 0) {
                    $bases[$idx] = bcsub($bases[$idx], $tick, $scale);
                }
            }
        }

        return array_values($bases);
    }

    /**
     * Allocate an inclusive (net + VAT) target across the SOURCE lines of a
     * single tax-rate group. NEVER throws (RULING A, 2026-08-03 re-gate):
     * searches for an EXACT split first (byte-identical to what
     * TaxCalculationService::calculateDocumentTaxes() will independently
     * recompute at confirm()); if the target is genuinely unreachable under
     * truncation-based VAT rounding (a proven "hole" -- e.g. 500.000 at 19%
     * sits between 499.999 and 500.001, reachable by NO net value), falls
     * back to the NEAREST REACHABLE value strictly <= target (`exact: false`
     * in the return) -- never above, never a silent drift, never an
     * exception.
     *
     * Per-line constraints, all enforced inside the search (2026-08-03
     * re-gate C1, and C2's quantity ceiling):
     * - discount_amount is NEVER negative (the documents API's own
     *   CreateDocumentRequest validates `min:0`) -- if a candidate quantity
     *   undershoots the target net, the quantity is bumped up (in ticks)
     *   until the gross covers it, rather than fabricating a negative
     *   ("surcharge") discount.
     * - a fabricated quantity never exceeds the SOURCE line's own quantity
     *   (a credit note can never credit more units than were sold).
     *
     * C2 SCOPE DECISION (2026-08-03 re-gate): the gate's own C2 finding
     * additionally asked that a fabricated quantity respect the product's
     * unit `decimal_places` (e.g. never "0.4244 pc" on a whole-unit
     * product) -- flagged P3 there, with the gate's own recommendation
     * being a PRESENTATIONAL fix ("show the credited amount, not a
     * synthetic quantity"), not a search change. Implemented and measured:
     * constraining the search itself to the unit's tick (flooring quantity,
     * dropping the discount-residual mechanism for coarse units) empirically
     * broke RULING A's own "deviation <= a few millimes" contract for the
     * MAJORITY of this tenant's catalogue -- 894 of 1097 local products
     * (81%) use a decimal_places=0 "Piece" unit, and MTP-DOC-16's live E2E
     * repro (100.000 requested against a 2x whole-unit-product invoice)
     * floored to 74.975 (a 25.025 TND deviation, not "a few millimes").
     * That is strictly worse than the display bug it was meant to fix, and
     * contradicts the very tests this ruling requires. Money-exactness
     * (the core of this lane) is kept; the quantity-precision DISPLAY
     * concern is left as the gate's own suggested follow-up (a template/
     * presentation change), out of scope for this service.
     *
     * @param  Collection<int, DocumentLine>  $sourceLines
     * @param  numeric-string  $targetInclusive
     * @param  numeric-string  $ratePercent
     * @return GroupAllocationResult
     */
    private function allocateGroupExactly(
        Collection $sourceLines,
        string $targetInclusive,
        string $ratePercent,
        int $scale
    ): array {
        // precision-ok: 6 = high-precision rate-fraction intermediate (matches TaxCalculationService's own rateFraction scale).
        $rateFraction = bcdiv($ratePercent, '100', 6);
        /** @var numeric-string $tick */
        $tick = bcdiv('1', bcpow('10', (string) $scale, 0), $scale);

        /** @var list<DocumentLine> $lines */
        $lines = $sourceLines->values()->all();
        /** @var list<numeric-string> $weights */
        $weights = array_map(fn (DocumentLine $l): string => $l->calculateTotal($scale), $lines);

        // Per-line quantity ceiling, computed ONCE (not per search
        // iteration): C2's quantity-ceiling half -- a fabricated quantity
        // can never exceed the source line's own quantity -- UNLESS the line
        // is the synthetic (no-lines-fallback) pseudo-line, which carries no
        // real product/quantity ceiling at all (it represents "amount of
        // 1.000-priced credit units", not a physical sale line).
        /** @var array<int, array{maxQty: numeric-string, unitPrice: numeric-string}> $lineMeta */
        $lineMeta = [];
        foreach ($lines as $i => $line) {
            $lineMeta[$i] = [
                'maxQty' => $line->product_id === null ? '999999999999.0000' : (string) $line->quantity,
                'unitPrice' => (string) $line->unit_price,
            ];
        }

        // precision-ok: 6 = high-precision rate-fraction intermediate, same as above.
        /** @var numeric-string $estimateNet */
        $estimateNet = bcdiv($targetInclusive, bcadd('1', $rateFraction, 6), $scale);

        // Bounded search: per-line quantity quantisation leaves a remainder
        // strictly smaller than one currency tick per line, so a handful of
        // ticks either side of the division estimate is always enough to
        // land exactly when the target is reachable at all (proven against
        // the live 50.174 repro).
        /** @var list<numeric-string> $offsets */
        $offsets = ['0'];
        for ($i = 1; $i <= 10; $i++) {
            $offsets[] = bcmul((string) $i, $tick, $scale);
            $offsets[] = bcmul((string) (-$i), $tick, $scale);
        }

        // Sub-tick sweep: because TaxCalculationService rounds VAT via
        // TRUNCATION (not half-up), the net->inclusive mapping has genuine
        // gaps. A quantity nudge shifts the qty*price product's 4th decimal
        // (a "delta" folded into the line's calculateTotal(scale+1) via the
        // discount_amount residual below) without moving the reported net --
        // extra resolution that closes those gaps. Only meaningful at native
        // 4dp quantity precision.
        /** @var list<numeric-string> $subTickDeltas */
        $subTickDeltas = ['0'];
        for ($i = 1; $i <= 9; $i++) {
            // precision-ok: 4 = canonical quantity storage scale (decimal:4), not a currency scale.
            $subTickDeltas[] = bcmul((string) $i, '0.0001', 4);
            // precision-ok: 4 = canonical quantity storage scale (decimal:4), not a currency scale.
            $subTickDeltas[] = bcmul((string) (-$i), '0.0001', 4);
        }

        /** @var array{net: numeric-string, vat: numeric-string, lines: list<ProratedLineSpec>, inclusive: numeric-string} $bestFloor */
        $bestFloor = ['net' => '0', 'vat' => '0', 'lines' => [], 'inclusive' => '0'];

        foreach ($offsets as $offset) {
            /** @var numeric-string $candidateNet */
            $candidateNet = bcadd($estimateNet, $offset, $scale);
            if (bccomp($candidateNet, '0', $scale) < 0) {
                continue;
            }

            $lineNets = $this->largestRemainderAllocate($candidateNet, $weights, $scale);

            foreach ($subTickDeltas as $subTickDelta) {
                /** @var list<ProratedLineSpec> $materialized */
                $materialized = [];
                /** @var numeric-string $taxAccumulator */
                $taxAccumulator = '0';
                // A2 (2026-08-03 re-gate): apply the sub-tick knob to the
                // FIRST line that actually SURVIVES the skip filters below --
                // not literal index 0, which is inert (and collapses the
                // knob for the whole group) whenever the first line is
                // zero-priced or received zero ticks from the largest-
                // remainder split.
                $deltaSlotClaimed = false;

                foreach ($lines as $i => $line) {
                    /** @var numeric-string $targetNet */
                    $targetNet = $lineNets[$i];
                    if (bccomp($targetNet, '0', $scale) <= 0) {
                        continue;
                    }

                    $meta = $lineMeta[$i];
                    $unitPrice = $meta['unitPrice'];
                    if (bccomp($unitPrice, '0', $scale) <= 0) {
                        continue;
                    }

                    $maxQty = $meta['maxQty'];

                    [$quantity, $lineTargetNet, $discountAmount, $netAtScalePlus1] = $this->materializeNativePrecisionLine(
                        $targetNet,
                        $unitPrice,
                        $maxQty,
                        $deltaSlotClaimed ? '0' : $subTickDelta,
                        $scale,
                    );
                    $deltaSlotClaimed = true;

                    /** @var numeric-string $lineTax */
                    $lineTax = bcmul($netAtScalePlus1, $rateFraction, $scale + 1);
                    $taxAccumulator = bcadd($taxAccumulator, $lineTax, $scale + 1);

                    $materialized[] = [
                        'source' => $line,
                        'quantity' => $quantity,
                        'unitPrice' => $unitPrice,
                        'discountAmount' => $discountAmount,
                        'targetNet' => $lineTargetNet,
                        'rate' => $ratePercent,
                    ];
                }

                if ($materialized === []) {
                    continue;
                }

                /** @var numeric-string $groupVat */
                $groupVat = CurrencyScale::bcformat($taxAccumulator, $scale);

                // The ACTUAL net sum from what was really materialised (may
                // be < candidateNet if any line hit its maxQty/coarse-unit
                // ceiling below its allocated share -- RULING A floor).
                /** @var numeric-string $actualNet */
                $actualNet = '0';
                foreach ($materialized as $m) {
                    $actualNet = bcadd($actualNet, $m['targetNet'], $scale);
                }
                /** @var numeric-string $actualInclusive */
                $actualInclusive = bcadd($actualNet, $groupVat, $scale);

                if (bccomp($actualInclusive, $targetInclusive, $scale) === 0) {
                    return ['net' => $actualNet, 'vat' => $groupVat, 'lines' => $materialized, 'exact' => true];
                }

                if (bccomp($actualInclusive, $targetInclusive, $scale) <= 0
                    && bccomp($actualInclusive, $bestFloor['inclusive'], $scale) > 0) {
                    $bestFloor = ['net' => $actualNet, 'vat' => $groupVat, 'lines' => $materialized, 'inclusive' => $actualInclusive];
                }
            }
        }

        return ['net' => $bestFloor['net'], 'vat' => $bestFloor['vat'], 'lines' => $bestFloor['lines'], 'exact' => false];
    }

    /**
     * Materialise a single line at NATIVE (>= 4dp) quantity precision: the
     * money-exact search + non-negative-discount residual mechanism.
     *
     * @param  numeric-string  $targetNet
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $maxQty
     * @param  numeric-string  $subTickDelta
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string, 3: numeric-string} [quantity, lineTargetNet, discountAmount, netAtScalePlus1]
     */
    private function materializeNativePrecisionLine(
        string $targetNet,
        string $unitPrice,
        string $maxQty,
        string $subTickDelta,
        int $scale
    ): array {
        /** @var numeric-string $quantity */
        $quantity = CurrencyScale::bcround(bcdiv($targetNet, $unitPrice, $scale + 6), 4);
        // precision-ok: 4 = canonical quantity comparison scale.
        if (bccomp($subTickDelta, '0', 4) !== 0) {
            // precision-ok: 4 = canonical quantity storage scale (decimal:4).
            $quantity = bcadd($quantity, $subTickDelta, 4);
        }
        // precision-ok: 4 = canonical quantity comparison scale.
        if (bccomp($quantity, '0', 4) <= 0) {
            $quantity = '0.0001';
        }
        // C2: never credit more units than were sold.
        // precision-ok: 4 = canonical quantity comparison scale.
        if (bccomp($quantity, $maxQty, 4) > 0) {
            $quantity = $maxQty;
        }

        /** @var numeric-string $gross3 */
        $gross3 = bcmul($quantity, $unitPrice, $scale);

        // C1 (2026-08-03 re-gate): never fabricate a NEGATIVE discount_amount
        // -- if the candidate quantity undershoots the target net, bump the
        // quantity up (bounded by maxQty) in ticks until gross3 >= targetNet.
        // Computed analytically (ceil of the shortfall / per-tick gain) with
        // a small bounded correction loop for the rare truncation-boundary
        // miss, instead of an unbounded per-tick loop (unsafe for tiny
        // unit_price values, where a single 0.0001 qty tick can move gross by
        // far less than one currency tick).
        if (bccomp($gross3, $targetNet, $scale) < 0 && bccomp($quantity, $maxQty, 4) < 0) {
            /** @var numeric-string $shortfall */
            $shortfall = bcsub($targetNet, $gross3, $scale + 6);
            /** @var numeric-string $perTickGain */
            $perTickGain = bcmul($unitPrice, '0.0001', $scale + 6);
            if (bccomp($perTickGain, '0', $scale + 6) > 0) {
                // precision-ok: 0 = an integer TICK COUNT, not a monetary amount.
                $ticksNeeded = (int) bcdiv($shortfall, $perTickGain, 0) + 1;
                /** @var numeric-string $bumped */
                $bumped = bcadd($quantity, bcmul((string) $ticksNeeded, '0.0001', 4), 4);
                // precision-ok: 4 = canonical quantity comparison scale.
                if (bccomp($bumped, $maxQty, 4) > 0) {
                    $bumped = $maxQty;
                }
                $quantity = $bumped;
                $gross3 = bcmul($quantity, $unitPrice, $scale);

                $safety = 0;
                while (bccomp($gross3, $targetNet, $scale) < 0 && bccomp($quantity, $maxQty, 4) < 0 && $safety < 20) {
                    $quantity = bcadd($quantity, '0.0001', 4);
                    // precision-ok: 4 = canonical quantity comparison scale.
                    if (bccomp($quantity, $maxQty, 4) > 0) {
                        $quantity = $maxQty;
                    }
                    $gross3 = bcmul($quantity, $unitPrice, $scale);
                    $safety++;
                }
            }
        }

        /** @var numeric-string $gross4 */
        $gross4 = bcmul($quantity, $unitPrice, $scale + 1);

        if (bccomp($gross3, $targetNet, $scale) < 0) {
            // Hit the maxQty ceiling before reaching the allocated share --
            // this line simply contributes LESS than its target (RULING A
            // floor), never a negative discount.
            /** @var numeric-string $lineTargetNet */
            $lineTargetNet = $gross3;
            /** @var numeric-string $discountAmount */
            $discountAmount = '0';
        } else {
            $lineTargetNet = $targetNet;
            /** @var numeric-string $discountAmount */
            $discountAmount = bcsub($gross3, $targetNet, $scale);
        }

        /** @var numeric-string $netAtScalePlus1 */
        $netAtScalePlus1 = bcsub($gross4, $discountAmount, $scale + 1);

        return [$quantity, $lineTargetNet, $discountAmount, $netAtScalePlus1];
    }

    /**
     * Allocate an amount-based credit across an invoice's real lines.
     *
     * ORCHESTRATOR RULING (2026-08-03, root-cause of orchestrator smoke
     * finding #1 -- 50.000 posting at 50.174), AMENDED by RULING A
     * (2026-08-03 re-gate): "amount" is the VAT-inclusive value the operator
     * wants to credit, EXCLUDING document-level duties. The returned
     * subtotal + taxAmount reconstruct $amount EXACTLY where reachable; in a
     * genuine truncation "hole" (or where a source line's unit precision or
     * remaining quantity caps what can physically be credited), the result
     * is quantized DOWN to the nearest reachable amount, `effectiveAmount <=
     * $amount` always, `exact` reports which case applies -- NEVER an
     * exception, NEVER a value above the request.
     *
     * @param  numeric-string  $amount
     * @return array{subtotal: numeric-string, taxAmount: numeric-string, lines: list<ProratedLineSpec>, effectiveAmount: numeric-string, exact: bool}
     */
    private function allocateAmountAcrossInvoiceLines(Document $invoice, string $amount, int $scale): array
    {
        /** @var Collection<int, DocumentLine> $sourceLines */
        $sourceLines = $invoice->lines;

        if ($sourceLines->isEmpty() || bccomp((string) ($invoice->total ?? '0'), '0', $scale) <= 0) {
            return $this->allocateAmountWithoutLines($invoice, $amount, $scale);
        }

        /** @var BaseCollection<string, Collection<int, DocumentLine>> $groups */
        $groups = $sourceLines->groupBy(fn (DocumentLine $l): string => (string) ($l->tax_rate ?? '0'));

        /** @var list<numeric-string> $rateKeys */
        $rateKeys = [];
        /** @var list<numeric-string> $groupWeights */
        $groupWeights = [];

        foreach ($groups as $rateKey => $linesInGroup) {
            /** @var numeric-string $rateKeyStr */
            $rateKeyStr = (string) $rateKey;
            $rateKeys[] = $rateKeyStr;

            /** @var numeric-string $groupNet */
            $groupNet = '0';
            foreach ($linesInGroup as $l) {
                $groupNet = bcadd($groupNet, $l->calculateTotal($scale), $scale);
            }

            // precision-ok: 6 = high-precision rate-fraction intermediate.
            $rateFraction = bcdiv($rateKeyStr, '100', 6);
            /** @var numeric-string $groupVat */
            $groupVat = CurrencyScale::bcformat(bcmul($groupNet, $rateFraction, $scale + 1), $scale);
            $groupWeights[] = bcadd($groupNet, $groupVat, $scale);
        }

        $groupTargets = $this->largestRemainderAllocate($amount, $groupWeights, $scale);

        /** @var numeric-string $subtotal */
        $subtotal = '0';
        /** @var numeric-string $taxAmount */
        $taxAmount = '0';
        /** @var list<ProratedLineSpec> $lines */
        $lines = [];
        $allExact = true;

        foreach ($rateKeys as $i => $rateKey) {
            /** @var numeric-string $targetInclusive */
            $targetInclusive = $groupTargets[$i];
            if (bccomp($targetInclusive, '0', $scale) <= 0) {
                continue;
            }

            /** @var Collection<int, DocumentLine> $groupLines */
            $groupLines = $groups->get($rateKey) ?? new Collection;
            $result = $this->allocateGroupExactly($groupLines, $targetInclusive, $rateKey, $scale);
            if (! $result['exact']) {
                $allExact = false;
            }
            $subtotal = bcadd($subtotal, $result['net'], $scale);
            $taxAmount = bcadd($taxAmount, $result['vat'], $scale);

            foreach ($result['lines'] as $lineSpec) {
                $lines[] = $lineSpec;
            }
        }

        if ($lines === []) {
            // Degenerate case: every source line is zero-priced (or the whole
            // group weight collapsed to zero), so proration by money share is
            // impossible. Fall back to the invoice-level split so the credit
            // note is never line-less (confirm()'s tax recompute would
            // otherwise collapse the total to a stamp-only figure).
            return $this->allocateAmountWithoutLines($invoice, $amount, $scale);
        }

        /** @var numeric-string $effectiveAmount */
        $effectiveAmount = bcadd($subtotal, $taxAmount, $scale);

        return [
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'lines' => $lines,
            'effectiveAmount' => $effectiveAmount,
            'exact' => $allExact,
        ];
    }

    /**
     * Fallback for an invoice with no materialised lines (or a zero total):
     * a single synthetic line at the invoice's blended VAT-only rate,
     * allocated via the same exact-reconstruction (or nearest-reachable
     * floor, RULING A) search as real lines.
     *
     * The blended rate EXCLUDES the invoice's own document-level duty (e.g.
     * STAMP_TAX_INVOICE) -- this is the other half of the 50.174 root cause:
     * folding a proportional share of a FIXED document duty into a per-unit
     * VAT rate systematically drifts the reconstructed total.
     *
     * @param  numeric-string  $amount
     * @return array{subtotal: numeric-string, taxAmount: numeric-string, lines: list<ProratedLineSpec>, effectiveAmount: numeric-string, exact: bool}
     */
    private function allocateAmountWithoutLines(Document $invoice, string $amount, int $scale): array
    {
        /** @var numeric-string $invoiceSubtotal */
        $invoiceSubtotal = (string) ($invoice->subtotal ?? '0');

        /** @var numeric-string $invoiceDocumentTax */
        $invoiceDocumentTax = $this->taxCalculationService->calculateDocumentTaxes($invoice)->documentTaxTotal;
        /** @var numeric-string $invoiceTaxAmount */
        $invoiceTaxAmount = (string) ($invoice->tax_amount ?? '0');
        /** @var numeric-string $invoiceLineItemsTaxOnly */
        $invoiceLineItemsTaxOnly = bcsub($invoiceTaxAmount, $invoiceDocumentTax, $scale);

        // precision-ok: 6 = high-precision rate intermediate; 2 = tax_rate column scale.
        /** @var numeric-string $blendedRate */
        $blendedRate = bccomp($invoiceSubtotal, '0', $scale) > 0
            ? bcmul(bcdiv($invoiceLineItemsTaxOnly, $invoiceSubtotal, 6), '100', 2)
            : '0';

        // precision-ok: 2 = tax_rate column scale.
        if (bccomp($blendedRate, '0', 2) <= 0) {
            return [
                'subtotal' => $amount,
                'taxAmount' => '0',
                'lines' => [[
                    'source' => null,
                    'quantity' => '1',
                    'unitPrice' => $amount,
                    'discountAmount' => '0',
                    'targetNet' => $amount,
                    'rate' => '0.00',
                ]],
                'effectiveAmount' => $amount,
                'exact' => true,
            ];
        }

        // Delegate to the SAME exact-reconstruction / nearest-reachable-floor
        // search allocateGroupExactly() uses for real invoice lines, against
        // one synthetic (unsaved) line. unit_price is pinned at 1.000 -- NOT
        // the net share itself -- because TaxCalculationService rounds VAT
        // via truncation (not half-up), so the net->inclusive mapping has
        // genuine unreachable "holes". A unit_price of 1.000 keeps the
        // quantity's own 4th decimal digit as a fine (0.0001-grained) search
        // knob via the qty*price truncation asymmetry allocateGroupExactly
        // already exploits, closing most of those holes; any that remain are
        // handled by RULING A's floor. quantity ends up reading as "amount
        // of 1.000-priced credit units", the same synthetic-line convention
        // the old single-line fallback used. No real product is attached, so
        // C2 (unit precision) does not apply here.
        $syntheticLine = new DocumentLine([
            'description' => 'Credit',
            'quantity' => '1',
            'unit_price' => '1.000',
            'tax_rate' => $blendedRate,
        ]);

        /** @var Collection<int, DocumentLine> $syntheticLines */
        $syntheticLines = new Collection([$syntheticLine]);

        $result = $this->allocateGroupExactly($syntheticLines, $amount, $blendedRate, $scale);

        /** @var numeric-string $effectiveAmount */
        $effectiveAmount = bcadd($result['net'], $result['vat'], $scale);

        return [
            'subtotal' => $result['net'],
            'taxAmount' => $result['vat'],
            'lines' => $result['lines'],
            'effectiveAmount' => $effectiveAmount,
            'exact' => $result['exact'],
        ];
    }

    /**
     * @param  list<ProratedLineSpec>  $lineSpecs
     */
    private function materializeLinesFromAllocation(Document $creditNote, array $lineSpecs): void
    {
        $lineNumber = 1;
        foreach ($lineSpecs as $spec) {
            $source = $spec['source'];

            DocumentLine::create([
                'document_id' => $creditNote->id,
                'product_id' => $source === null ? null : $source->product_id,
                'line_number' => $lineNumber++,
                'description' => $source === null ? 'Credit' : $source->description,
                'quantity' => $spec['quantity'],
                'unit_price' => $spec['unitPrice'],
                'discount_percent' => null,
                'discount_amount' => $spec['discountAmount'],
                'tax_rate' => $spec['rate'],
                'line_total' => $spec['targetNet'],
                'designation_default_snapshot' => $source === null ? null : $source->designation_default_snapshot,
            ]);
        }
    }

    /**
     * Create a credit note from a source invoice.
     *
     * @throws \InvalidArgumentException
     */
    public function createCreditNote(
        string $sourceInvoiceId,
        string $amount,
        CreditNoteReason $reason,
        ?string $notes = null
    ): Document {
        // O-26 (owner ruling 2026-08-21) — a credit note for ZERO is refused
        // before anything is created. The request validator's money regex accepts
        // `"0"` (`CreditNoteController::store()`), and this entry point had no
        // positivity check even though its converter sibling has had one all
        // along (`InvoiceToCreditNoteConverter::convertAmountBased()`). A zero
        // credit note credits nothing, and it was one of the ways to mint a
        // LINELESS credit note — which the posting refusal then blocks, on a
        // document type that has no update route to fix it with. Refusing here
        // closes the path at the door instead. Same exception type and 422
        // `VALIDATION_ERROR` mapping as every other pre-condition in this method.
        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('Credit note amount must be a positive number');
        }

        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return DB::transaction(function () use ($sourceInvoiceId, $amount, $reason, $notes, $tenantId, $companyId): Document {
            // 1. Lock the invoice row FIRST (pessimistic locking)
            // api.document.005: tenant+company scoped read so a cross-tenant
            // sourceInvoiceId surfaces as ModelNotFoundException, not a
            // foreign Document instance.
            /** @var Document $invoice */
            $invoice = Document::with('lines')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($sourceInvoiceId);

            // 2. Validate INSIDE transaction (race-safe)
            // Validation: Only posted invoices can have credit notes
            if (! $invoice->isPosted()) {
                throw new \InvalidArgumentException('Credit notes can only be created for posted invoices');
            }

            // Validation: Credit note amount cannot exceed invoice total
            // precision-ok: 4 = canonical amount comparison scale (guard predates the currency scale resolver; pre-existing).
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($amount, $invoice->total, 4) > 0) {
                throw new \InvalidArgumentException('Credit note amount cannot exceed invoice total');
            }

            $scale = $this->scaleFor($invoice);

            // Validation: Total credit notes cannot exceed the invoice's
            // remaining duty-EXCLUSIVE headroom (RULING B, 2026-08-03
            // re-gate -- closes D1). This is a soft pre-check against the
            // RAW requested amount; since the allocation below can only ever
            // produce an effective amount <= $amount (RULING A), passing
            // this check on the request is sufficient.
            $remaining = $this->remainingCreditHeadroom($invoice, $scale);
            // precision-ok: 4 = canonical amount comparison scale (pre-existing guard).
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($amount, $remaining, 4) > 0) {
                throw new \InvalidArgumentException('Total credit notes would exceed invoice total');
            }

            // ORCHESTRATOR RULING, amended by RULING A: reconstruct $amount
            // across the invoice's real lines where reachable; otherwise
            // quantize DOWN to the nearest reachable amount (never above,
            // never an exception) before any document-level duty is folded
            // in.
            /** @phpstan-ignore-next-line argument.type */
            $allocation = $this->allocateAmountAcrossInvoiceLines($invoice, $amount, $scale);
            /** @var numeric-string $effectiveAmount */
            $effectiveAmount = $allocation['effectiveAmount'];

            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'location_id' => $invoice->location_id,
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => null,
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => $allocation['subtotal'],
                'tax_amount' => $allocation['taxAmount'],
                'total' => $effectiveAmount,
                'source_document_id' => $invoice->id,
                'credit_note_reason' => $reason,
                'notes' => $notes,
            ]);

            // Copy vehicle context if exists
            if ($invoice->vehicleContext) {
                DocumentVehicleContext::create([
                    'document_id' => $creditNote->id,
                    'vehicle_id' => $invoice->vehicleContext->vehicle_id,
                    'vehicle_snapshot' => $invoice->vehicleContext->vehicle_snapshot,
                    'mileage_at_service' => $invoice->vehicleContext->mileage_at_service,
                    'context_data' => $invoice->vehicleContext->context_data,
                ]);
            }

            // Materialise prorated lines from the source invoice. Without lines,
            // confirm()'s tax recompute runs on an empty document and collapses
            // the total to a stamp-only figure → a single unbalanced GL line
            // (bug #2). Prorated lines give revenue/VAT/AR real data and keep
            // the reversal balanced.
            $this->materializeLinesFromAllocation($creditNote, $allocation['lines']);

            // Fold the credit note's own document-level duty (e.g. the 0.600
            // TND STAMP_CREDIT_NOTE) in via the SAME pipeline confirm() uses,
            // so the Draft's total already equals its post-confirmation total.
            $this->applyConfirmEquivalentTotals($creditNote, $scale);

            // RULING A: surface requested vs effective on the create response
            // whenever they differ (a genuine unreachable-target quantization
            // or a physical ceiling like a source line's own quantity). Set
            // via setAttribute() -- an IN-MEMORY-ONLY attribute (neither
            // column is persisted; a subsequent GET/show re-fetches a fresh
            // model without them, since the quantization is a create-time
            // event) -- never through mass-assignment/$fillable.
            // precision-ok: 4 = canonical amount comparison scale.
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($effectiveAmount, $amount, 4) !== 0) {
                $creditNote->setAttribute('requested_amount', $amount);
                $creditNote->setAttribute('credited_amount', $effectiveAmount);
            }

            return $creditNote;
        });
    }

    /**
     * Create a line-based credit note from selected invoice lines.
     *
     * @param  array<array{line_id: string, quantity: numeric}>  $lines
     *
     * @throws \InvalidArgumentException
     */
    public function createLineBasedCreditNote(
        string $sourceInvoiceId,
        array $lines,
        CreditNoteReason $reason,
        ?string $notes = null
    ): Document {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return DB::transaction(function () use ($sourceInvoiceId, $lines, $reason, $notes, $tenantId, $companyId): Document {
            // 1. Lock the invoice row FIRST (pessimistic locking)
            // api.document.006: tenant+company scoped read.
            /** @var Document $invoice */
            $invoice = Document::with('lines')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($sourceInvoiceId);

            // 2. Validate INSIDE transaction (race-safe)
            // Validation: Only posted invoices can have credit notes
            if (! $invoice->isPosted()) {
                throw new \InvalidArgumentException('Credit notes can only be created for posted invoices');
            }

            $scale = $this->scaleFor($invoice);

            /** @var array<string, array{invoiceLine: DocumentLine, quantity: numeric-string}> $lineMap */
            $lineMap = [];

            foreach ($lines as $lineData) {
                /** @var string $lineId */
                $lineId = $lineData['line_id'];

                /** @var DocumentLine|null $invoiceLine */
                $invoiceLine = $invoice->lines()
                    ->where('id', $lineId)
                    ->first();

                if ($invoiceLine === null) {
                    throw new \InvalidArgumentException("Line {$lineId} not found in invoice");
                }

                /** @var numeric-string $quantity */
                $quantity = (string) $lineData['quantity'];

                // Validate quantity
                // precision-ok: 4 = canonical quantity comparison scale.
                if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $invoiceLine->quantity, 4) > 0) {
                    throw new \InvalidArgumentException('Invalid quantity for line');
                }

                $lineMap[$lineId] = [
                    'invoiceLine' => $invoiceLine,
                    'quantity' => $quantity,
                ];
            }

            // Create the credit note shell with placeholder totals -- real
            // totals are computed AFTER the lines exist, via
            // applyConfirmEquivalentTotals() (the SAME pipeline confirm()
            // uses), so draft == confirm byte-for-byte (closes B1's discount
            // bug and B3/R1's per-line truncation divergence in one move).
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'location_id' => $invoice->location_id,
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => null,
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => '0',
                'tax_amount' => '0',
                'total' => '0',
                'source_document_id' => $invoice->id,
                'credit_note_reason' => $reason,
                'notes' => $notes,
            ]);

            // Copy vehicle context from original invoice
            if ($invoice->vehicleContext !== null) {
                DocumentVehicleContext::create([
                    'document_id' => $creditNote->id,
                    'vehicle_id' => $invoice->vehicleContext->vehicle_id,
                    'vehicle_snapshot' => $invoice->vehicleContext->vehicle_snapshot,
                    'mileage_at_service' => $invoice->vehicleContext->mileage_at_service,
                    'context_data' => $invoice->vehicleContext->context_data,
                ]);
            }

            // Create credit note lines. BLOCKER 2 (2026-08-03 re-gate, B1):
            // the flat discount_amount is PRORATED by the credited quantity
            // ratio -- never copied wholesale. Crediting 1 of 10 units on a
            // line with a flat 50.000 discount must not drag the whole
            // 50.000 onto the 1-unit credit line (which would have gone net-
            // negative at confirm()). discount_percent needs no proration --
            // it already scales with whatever base it is applied to.
            $lineNumber = 1;
            foreach ($lineMap as $lineId => $data) {
                /** @var DocumentLine $invoiceLine */
                $invoiceLine = $data['invoiceLine'];
                /** @var numeric-string $quantity */
                $quantity = $data['quantity'];

                $discountPercent = $invoiceLine->discount_percent;
                $discountAmount = null;
                if ($invoiceLine->discount_amount !== null && bccomp((string) $invoiceLine->discount_amount, '0', $scale) !== 0) {
                    /** @var numeric-string $sourceQty */
                    $sourceQty = (string) $invoiceLine->quantity;
                    // precision-ok: 4 = canonical quantity comparison scale.
                    $ratio = bccomp($sourceQty, '0', 4) > 0
                        ? bcdiv($quantity, $sourceQty, 10) // precision-ok: 10 = high-precision proration ratio intermediate.
                        : '0';
                    /** @var numeric-string $discountAmount */
                    $discountAmount = bcmul((string) $invoiceLine->discount_amount, $ratio, $scale);
                }

                $lineTotal = DocumentLine::computeLineTotal(
                    $quantity,
                    (string) $invoiceLine->unit_price,
                    $discountPercent !== null ? (string) $discountPercent : null,
                    $discountAmount,
                    $scale,
                );

                DocumentLine::create([
                    'document_id' => $creditNote->id,
                    'product_id' => $invoiceLine->product_id,
                    'line_number' => $lineNumber,
                    'description' => $invoiceLine->description,
                    'quantity' => $quantity,
                    'unit_price' => $invoiceLine->unit_price,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $invoiceLine->tax_rate,
                    'line_total' => $lineTotal,
                    'notes' => $invoiceLine->notes,
                    'designation_default_snapshot' => $invoiceLine->designation_default_snapshot,
                ]);

                $lineNumber++;
            }

            // Compute the REAL totals from the persisted, discount-aware
            // lines via the confirm()-equivalent pipeline.
            $taxResult = $this->applyConfirmEquivalentTotals($creditNote, $scale);

            // Validation: Total credit notes cannot exceed the invoice's
            // remaining duty-EXCLUSIVE headroom (RULING B, 2026-08-03
            // re-gate). Runs AFTER the real totals are known -- a refusal
            // rolls back the whole transaction (document + lines), so it is
            // safe to check late. The duty-exclusive total for this CN is
            // subtotal + lineItemsTaxTotal (VAT only, excluding this CN's own
            // document-level duty).
            /** @var numeric-string $dutyExclusiveTotal */
            /** @phpstan-ignore-next-line argument.type */
            $dutyExclusiveTotal = bcadd($taxResult->subtotal, $taxResult->lineItemsTaxTotal, $scale);
            // Exclude the credit note being created: it already has a
            // persisted row (with its REAL total, computed just above) by
            // the time this guard runs, so without excluding it, it would
            // count against its own headroom and self-refuse.
            $remaining = $this->remainingCreditHeadroom($invoice, $scale, $creditNote->id);
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($dutyExclusiveTotal, $remaining, $scale) > 0) {
                throw new \InvalidArgumentException('Total credit notes would exceed invoice total');
            }

            return $creditNote;
        });
    }

    /**
     * Create a standalone credit note without source invoice.
     *
     * Used for direct customer compensation or adjustments not tied to a specific invoice.
     * These credit notes:
     * - Do NOT reduce any invoice balance
     * - Do NOT get allocated to invoices
     * - Can be used for customer goodwill, service compensation, etc.
     *
     * @param  string  $partnerId  Customer to credit
     * @param  array<int, array{product_id?: string, description: string, quantity: numeric-string|float, unit_price: numeric-string, tax_rate: numeric-string|float}>  $lines  Line items
     * @param  CreditNoteReason  $reason  Reason for the credit
     * @param  string|null  $notes  Optional notes
     *
     * @throws \InvalidArgumentException
     */
    public function createStandaloneCreditNote(
        string $partnerId,
        array $lines,
        CreditNoteReason $reason,
        ?string $notes = null
    ): Document {
        $company = $this->companyContext->requireCompany();
        $contextTenantId = $company->tenant_id;
        $contextCompanyId = $company->id;

        return DB::transaction(function () use ($partnerId, $lines, $reason, $notes, $contextTenantId, $contextCompanyId): Document {
            // Get partner to validate and extract company/tenant info.
            // api.document.007: tenant+company scoped — a cross-tenant partnerId
            // surfaces as ModelNotFoundException, never a foreign Partner.
            /** @var Partner $partner */
            $partner = Partner::query()
                ->where('tenant_id', $contextTenantId)
                ->where('company_id', $contextCompanyId)
                ->lockForUpdate()
                ->findOrFail($partnerId);

            $companyId = $partner->company_id;
            $tenantId = $partner->tenant_id;

            $currency = (string) ($partner->currency ?? 'TND');
            $scale = $currency !== ''
                ? $this->scaleResolver->getScale($currency)
                : $this->scaleResolver->getScaleSafe(null, 3);

            foreach ($lines as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];

                // Validate quantity and price
                // precision-ok: 4 = canonical quantity comparison scale.
                if (bccomp($quantity, '0', 4) <= 0) {
                    throw new \InvalidArgumentException('Line quantity must be greater than zero');
                }

                // precision-ok: 4 = canonical quantity/amount comparison scale.
                if (bccomp($unitPrice, '0', 4) < 0) {
                    throw new \InvalidArgumentException('Line unit price cannot be negative');
                }
            }

            // Create the credit note shell with placeholder totals -- real
            // totals are computed AFTER the lines exist, via
            // applyConfirmEquivalentTotals() (closes B3/R1: the old
            // hand-rolled per-line truncation loop is gone, so there is
            // nothing left to diverge from confirm()'s recompute).
            $creditNote = Document::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'location_id' => null, // No location for standalone credit notes
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => null,
                'document_date' => now(),
                'currency' => $currency,
                'subtotal' => '0',
                'tax_amount' => '0',
                'total' => '0',
                'source_document_id' => null, // No source invoice for standalone
                'credit_note_reason' => $reason,
                'notes' => $notes,
            ]);

            // Batch-fetch products for snapshot capture (1 query)
            $standaloneProdIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
            /** @var Collection<int, Product> $standaloneProducts */
            $standaloneProducts = Product::whereIn('id', $standaloneProdIds)->get()->keyBy('id');

            // Create credit note lines from provided data
            $lineNumber = 1;
            foreach ($lines as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];
                $taxRate = (string) $line['tax_rate'];
                $lineTotal = bcmul($quantity, $unitPrice, $scale);

                /** @var Product|null $standaloneProduct */
                $standaloneProduct = isset($line['product_id']) ? $standaloneProducts->get($line['product_id']) : null;
                $standaloneDefaultName = $standaloneProduct !== null ? (string) $standaloneProduct->name : '';

                DocumentLine::create([
                    'document_id' => $creditNote->id,
                    'product_id' => $line['product_id'] ?? null,
                    'line_number' => $lineNumber,
                    'description' => $line['description'],
                    'designation_default_snapshot' => $standaloneDefaultName !== '' ? mb_substr($standaloneDefaultName, 0, 500) : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_percent' => '0.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => $taxRate,
                    'line_total' => $lineTotal,
                    'notes' => null,
                ]);

                $lineNumber++;
            }

            // Compute the REAL totals from the persisted lines via the
            // confirm()-equivalent pipeline.
            $this->applyConfirmEquivalentTotals($creditNote, $scale);

            return $creditNote;
        });
    }

    /**
     * Allocate a posted credit note against its source invoice
     *
     * This reduces the invoice's balance_due and may change its payment status to Paid.
     * Called automatically when a credit note is posted.
     *
     * RULING B (2026-08-03 re-gate, closes D1): the allocation CLAMPS at the
     * invoice's remaining balance_due, floored at 0 -- a negative
     * balance_due must be structurally impossible regardless of how a credit
     * note's total was derived. Any excess (e.g. the CN's own duty pushing
     * its total past what the invoice has left to give) stays UNALLOCATED on
     * the credit note -- readable as `creditNote.total -
     * CreditNoteAllocation.amount`, no new column needed.
     *
     * Gate C-2 (2026-08-07, Q1 lane) — the amount being clamped is now the
     * credit note's EX-STAMP total (`total - stamp_duty_amount`), mirroring
     * `remainingCreditHeadroom()` above (already duty-exclusive). Q1: the
     * CN's own stamp is a fiscal charge borne by the company, never a
     * reduction of what the customer owes, so it must never reduce
     * `balance_due` either — allocating it would desync the GL's ex-stamp 411
     * movement (`AccountingService::createCreditNoteGLEntries()`) from the
     * document ledger's `balance_due`, the exact class of drift N1 raised.
     *
     * @param  string|null  $allocatedBy  User ID who is allocating the credit note (for audit trail)
     *
     * @throws \InvalidArgumentException
     */
    public function allocateCreditNote(Document $creditNote, ?string $allocatedBy = null): CreditNoteAllocation
    {
        if ($creditNote->type !== DocumentType::CreditNote) {
            throw new \InvalidArgumentException('Document must be a credit note');
        }

        if ($creditNote->status !== DocumentStatus::Posted) {
            throw new \InvalidArgumentException('Credit note must be posted to allocate');
        }

        if ($creditNote->source_document_id === null) {
            throw new \InvalidArgumentException('Credit note must have a source invoice');
        }

        return DB::transaction(function () use ($creditNote, $allocatedBy): CreditNoteAllocation {
            // Lock the source invoice.
            // api.document.008: defense-in-depth — scope by the credit note's
            // own tenant + company so a corrupted source_document_id pointing
            // across tenants surfaces as ModelNotFoundException rather than
            // allocating against a foreign invoice.
            $invoice = Document::query()
                ->where('tenant_id', $creditNote->tenant_id)
                ->where('company_id', $creditNote->company_id)
                ->lockForUpdate()
                ->findOrFail($creditNote->source_document_id);

            // Validate invoice type
            if ($invoice->type !== DocumentType::Invoice) {
                throw new \InvalidArgumentException('Source document must be an invoice');
            }

            // Treasury gate IMPORTANT — the invoice is required to be Posted only
            // at credit-note CREATION time; the credit note is created Draft and
            // allocated only once it is itself posted, and the source invoice can
            // be cancelled in that gap. Money against a withdrawn document.
            $this->allocationStateGuard->assertAllocatable($invoice);
            // W4-3 / gate r1 I-1 — applying a credit note settles the receivable too.
            $this->allocationStateGuard->assertDirectionMatchesPartner($invoice);

            $scale = $this->scaleFor($invoice);
            /** @var numeric-string $currentBalance */
            $currentBalance = (string) ($invoice->balance_due ?? $invoice->total ?? '0');

            // Q1 (gate C-2) — allocate EX-STAMP: the credit note's own
            // stamp_duty_amount never reduces what the customer owes.
            /** @var numeric-string $creditNoteStampDutyAmount */
            $creditNoteStampDutyAmount = (string) ($creditNote->stamp_duty_amount ?? '0');
            /** @var numeric-string $creditNoteDocumentTotal */
            $creditNoteDocumentTotal = (string) $creditNote->total;
            /** @var numeric-string $creditNoteTotal */
            $creditNoteTotal = bccomp($creditNoteStampDutyAmount, '0', $scale) > 0
                ? bcsub($creditNoteDocumentTotal, $creditNoteStampDutyAmount, $scale)
                : $creditNoteDocumentTotal;

            /** @var numeric-string $clampedAmount */
            $clampedAmount = bccomp($creditNoteTotal, $currentBalance, $scale) > 0 ? $currentBalance : $creditNoteTotal;
            if (bccomp($clampedAmount, '0', $scale) < 0) {
                $clampedAmount = '0';
            }

            // Create the allocation
            $allocation = CreditNoteAllocation::create([
                'credit_note_id' => $creditNote->id,
                'invoice_id' => $invoice->id,
                'amount' => $clampedAmount,
                'allocated_by' => $allocatedBy,
            ]);

            // Note: balance_due will be updated automatically by the PostgreSQL trigger

            return $allocation;
        });
    }
}
