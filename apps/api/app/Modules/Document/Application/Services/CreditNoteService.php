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
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
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
 */
class CreditNoteService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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
     * Fold the credit note's OWN document-level duty (e.g. the Tunisian
     * 0.600 TND STAMP_CREDIT_NOTE) into its draft totals.
     *
     * Same treatment as InvoiceController::withDocumentLevelTaxes() (`18e61a554`)
     * and CopiesDocumentData::recalculateTotals() -- a credit note's own
     * document-level tax was previously applied for the first time at
     * confirm() (docs/superpowers/tickets/2026-08-02-credit-note-draft-stamp-
     * and-scale4-totals.md, finding 3), so a Draft's total was short by
     * exactly the stamp until confirmation. Running the same
     * TaxCalculationService::calculateDocumentTaxes() pipeline here means a
     * draft's tax_amount/total already equal their post-confirmation values.
     *
     * Only `documentTaxTotal` is consumed -- never `totalTax` -- so an
     * explicit line rate with no matching TaxConfiguration row is never
     * silently re-zeroed (the line-item VAT already computed by the
     * allocation above is preserved exactly as-is).
     *
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $lineTaxAmount
     */
    private function foldDocumentLevelTaxes(Document $creditNote, string $subtotal, string $lineTaxAmount, int $scale): void
    {
        // Re-read the just-persisted lines so calculateDocumentTaxes() sees them.
        $creditNote->setRelation('lines', $creditNote->lines()->get());

        /** @var numeric-string $documentTaxTotal */
        $documentTaxTotal = $this->taxCalculationService->calculateDocumentTaxes($creditNote)->documentTaxTotal;

        /** @var numeric-string $taxAmount */
        $taxAmount = bcadd($lineTaxAmount, $documentTaxTotal, $scale);
        /** @var numeric-string $total */
        $total = bcadd($subtotal, $taxAmount, $scale);

        $creditNote->update([
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }

    /**
     * Distribute $total across $weights proportionally using the
     * largest-remainder method (Hamilton apportionment), so the allocations
     * sum to $total EXACTLY at $scale -- no lossy per-item truncation.
     * Non-positive weights get zero allocation.
     *
     * ORCHESTRATOR RULING (2026-08-03, root-cause of the 50.174 drift):
     * internal decomposition must reconstruct the requested amount exactly;
     * this is the shared primitive both the group-level (across tax rates)
     * and line-level (within a rate group) allocations use.
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
     * Allocate an exact inclusive (net + VAT) target across the SOURCE lines
     * of a single tax-rate group, searching for the net/VAT split whose
     * TaxCalculationService-identical recomputation reproduces $targetInclusive
     * byte-for-byte (never a lossy independent truncation).
     *
     * Each line keeps the source's own unit_price; only quantity and a
     * (possibly negative, i.e. a tiny true-up) discount_amount vary, so
     * `DocumentLine::computeLineTotal()` reconstructs the allocated net
     * exactly at the currency scale.
     *
     * @param  Collection<int, DocumentLine>  $sourceLines
     * @param  numeric-string  $targetInclusive
     * @param  numeric-string  $ratePercent
     * @return array{net: numeric-string, vat: numeric-string, lines: list<ProratedLineSpec>}
     */
    private function allocateGroupExactly(
        Collection $sourceLines,
        string $targetInclusive,
        string $ratePercent,
        int $scale
    ): array {
        // precision-ok: 6 = high-precision rate-fraction intermediate (matches TaxCalculationService's own rateFraction scale).
        /** @var numeric-string $rateFraction */
        $rateFraction = bcdiv($ratePercent, '100', 6);
        /** @var numeric-string $tick */
        $tick = bcdiv('1', bcpow('10', (string) $scale, 0), $scale);

        /** @var list<DocumentLine> $lines */
        $lines = $sourceLines->values()->all();
        /** @var list<numeric-string> $weights */
        $weights = array_map(fn (DocumentLine $l): string => $l->calculateTotal($scale), $lines);

        // precision-ok: 6 = high-precision rate-fraction intermediate, same as above.
        /** @var numeric-string $estimateNet */
        $estimateNet = bcdiv($targetInclusive, bcadd('1', $rateFraction, 6), $scale);

        // Bounded search: per-line quantity quantisation (4dp) leaves a
        // remainder strictly smaller than one currency tick per line, so a
        // handful of ticks either side of the division estimate is always
        // enough to land exactly (proven against the live 50.174 repro --
        // see the root-cause writeup in the credit-note money lane).
        /** @var list<numeric-string> $offsets */
        $offsets = ['0'];
        for ($i = 1; $i <= 10; $i++) {
            $offsets[] = bcmul((string) $i, $tick, $scale);
            $offsets[] = bcmul((string) (-$i), $tick, $scale);
        }

        // Sub-tick sweep applied to the FIRST line's quantity only: because
        // TaxCalculationService rounds VAT via TRUNCATION (not half-up), the
        // net->inclusive mapping has genuine gaps -- some inclusive targets
        // are unreachable by ANY single net value at the currency scale (a
        // 419.999 -> 500.001 "hole" straddling exactly 500.000, proven for a
        // 19% rate). A quantity nudge shifts the qty*price product's 4th
        // decimal (a "delta" folded into the line's calculateTotal(scale+1)
        // via the discount_amount residual below) without moving the
        // reported net -- extra resolution that closes those gaps.
        /** @var list<numeric-string> $subTickDeltas */
        $subTickDeltas = ['0'];
        for ($i = 1; $i <= 9; $i++) {
            // precision-ok: 4 = canonical quantity storage scale (decimal:4), not a currency scale.
            $subTickDeltas[] = bcmul((string) $i, '0.0001', 4);
            // precision-ok: 4 = canonical quantity storage scale (decimal:4), not a currency scale.
            $subTickDeltas[] = bcmul((string) (-$i), '0.0001', 4);
        }

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

                foreach ($lines as $i => $line) {
                    /** @var numeric-string $targetNet */
                    $targetNet = $lineNets[$i];
                    if (bccomp($targetNet, '0', $scale) <= 0) {
                        continue;
                    }

                    /** @var numeric-string $unitPrice */
                    $unitPrice = (string) $line->unit_price;
                    if (bccomp($unitPrice, '0', $scale) <= 0) {
                        continue;
                    }

                    /** @var numeric-string $quantity */
                    $quantity = CurrencyScale::bcround(bcdiv($targetNet, $unitPrice, $scale + 6), 4);
                    // Only the first line carries the sub-tick sweep -- one
                    // knob is enough search freedom and keeps the search
                    // space linear instead of combinatorial across lines.
                    // precision-ok: 4 = canonical quantity comparison scale.
                    if ($i === 0 && bccomp($subTickDelta, '0', 4) !== 0) {
                        // precision-ok: 4 = canonical quantity storage scale (decimal:4).
                        $quantity = bcadd($quantity, $subTickDelta, 4);
                    }
                    // precision-ok: 4 = canonical quantity comparison scale.
                    if (bccomp($quantity, '0', 4) <= 0) {
                        $quantity = '0.0001';
                    }

                    /** @var numeric-string $gross3 */
                    $gross3 = bcmul($quantity, $unitPrice, $scale);
                    /** @var numeric-string $gross4 */
                    $gross4 = bcmul($quantity, $unitPrice, $scale + 1);
                    /** @var numeric-string $discountAmount */
                    $discountAmount = bcsub($gross3, $targetNet, $scale);
                    /** @var numeric-string $netAtScalePlus1 */
                    $netAtScalePlus1 = bcsub($gross4, $discountAmount, $scale + 1);

                    /** @var numeric-string $lineTax */
                    $lineTax = bcmul($netAtScalePlus1, $rateFraction, $scale + 1);
                    $taxAccumulator = bcadd($taxAccumulator, $lineTax, $scale + 1);

                    $materialized[] = [
                        'source' => $line,
                        'quantity' => $quantity,
                        'unitPrice' => $unitPrice,
                        'discountAmount' => $discountAmount,
                        'targetNet' => $targetNet,
                        'rate' => $ratePercent,
                    ];
                }

                if ($materialized === []) {
                    continue;
                }

                /** @var numeric-string $groupVat */
                $groupVat = CurrencyScale::bcformat($taxAccumulator, $scale);
                /** @var numeric-string $actualInclusive */
                $actualInclusive = bcadd($candidateNet, $groupVat, $scale);

                if (bccomp($actualInclusive, $targetInclusive, $scale) === 0) {
                    return ['net' => $candidateNet, 'vat' => $groupVat, 'lines' => $materialized];
                }
            }
        }

        throw new \RuntimeException(
            'Unable to allocate the credit-note amount exactly across the source invoice lines '
            .'(bounded largest-remainder search exhausted for rate '.$ratePercent.'%).'
        );
    }

    /**
     * Allocate an amount-based credit across an invoice's real lines.
     *
     * ORCHESTRATOR RULING (2026-08-03, root-cause of orchestrator smoke
     * finding #1 -- 50.000 posting at 50.174): "amount" is the VAT-inclusive
     * value the operator wants to credit, EXCLUDING document-level duties.
     * The returned subtotal + taxAmount reconstruct $amount EXACTLY via
     * largest-remainder allocation, first across tax-rate groups then across
     * lines within each group -- never a lossy independent per-line
     * truncation -- so TaxCalculationService::calculateDocumentTaxes(), run
     * on these exact lines at confirm(), reproduces subtotal+lineItemsTaxTotal
     * == $amount byte-for-byte. The document's own duty (e.g. the 0.600 TND
     * STAMP_CREDIT_NOTE) is folded in separately by foldDocumentLevelTaxes().
     *
     * @param  numeric-string  $amount
     * @return array{subtotal: numeric-string, taxAmount: numeric-string, lines: list<ProratedLineSpec>}
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
            /** @var numeric-string $rateFraction */
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

        foreach ($rateKeys as $i => $rateKey) {
            /** @var numeric-string $targetInclusive */
            $targetInclusive = $groupTargets[$i];
            if (bccomp($targetInclusive, '0', $scale) <= 0) {
                continue;
            }

            /** @var Collection<int, DocumentLine> $groupLines */
            $groupLines = $groups->get($rateKey) ?? new Collection;
            $result = $this->allocateGroupExactly($groupLines, $targetInclusive, $rateKey, $scale);
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

        return ['subtotal' => $subtotal, 'taxAmount' => $taxAmount, 'lines' => $lines];
    }

    /**
     * Fallback for an invoice with no materialised lines (or a zero total):
     * a single synthetic line at the invoice's blended VAT-only rate,
     * allocated via the same exact-reconstruction search as real lines.
     *
     * The blended rate EXCLUDES the invoice's own document-level duty (e.g.
     * STAMP_TAX_INVOICE) -- this is the other half of the 50.174 root cause:
     * folding a proportional share of a FIXED document duty into a per-unit
     * VAT rate systematically drifts the reconstructed total.
     *
     * @param  numeric-string  $amount
     * @return array{subtotal: numeric-string, taxAmount: numeric-string, lines: list<ProratedLineSpec>}
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

        /** @var numeric-string $blendedRate */
        $blendedRate = bccomp($invoiceSubtotal, '0', $scale) > 0
            // precision-ok: 6 = high-precision rate intermediate; 2 = tax_rate column scale.
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
            ];
        }

        // Delegate to the SAME exact-reconstruction search allocateGroupExactly
        // uses for real invoice lines, against one synthetic (unsaved) line.
        // unit_price is pinned at 1.000 -- NOT the net share itself -- because
        // TaxCalculationService rounds VAT via truncation (not half-up), so
        // the net->inclusive mapping has genuine unreachable "holes" (e.g.
        // 500.000 at 19% sits exactly between 499.999 and 500.001, reachable
        // by NO net value). A unit_price of 1.000 keeps the quantity's own
        // 4th decimal digit as a fine (0.0001-grained) search knob via the
        // qty*price truncation asymmetry allocateGroupExactly already
        // exploits, closing those holes. quantity ends up reading as "amount
        // of 1.000-priced credit units", the same synthetic-line convention
        // the old single-line fallback used.
        $syntheticLine = new DocumentLine([
            'description' => 'Credit',
            'quantity' => '1',
            'unit_price' => '1.000',
            'tax_rate' => $blendedRate,
        ]);

        /** @var Collection<int, DocumentLine> $syntheticLines */
        $syntheticLines = new Collection([$syntheticLine]);

        $result = $this->allocateGroupExactly($syntheticLines, $amount, $blendedRate, $scale);

        return ['subtotal' => $result['net'], 'taxAmount' => $result['vat'], 'lines' => $result['lines']];
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

            // Validation: Total credit notes cannot exceed invoice total
            /** @var numeric-string $totalCreditNotes */
            $totalCreditNotes = $invoice->creditNotes()->sum('total');
            // precision-ok: 4 = canonical amount comparison scale (pre-existing guard).
            /** @phpstan-ignore-next-line argument.type */
            $remainingAmount = bcsub($invoice->total, (string) $totalCreditNotes, 4);

            // precision-ok: 4 = canonical amount comparison scale (pre-existing guard).
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($amount, $remainingAmount, 4) > 0) {
                throw new \InvalidArgumentException('Total credit notes would exceed invoice total');
            }

            $creditNoteNumber = $this->numberingService->generateNumber($tenantId, $invoice->company_id, DocumentType::CreditNote);

            $scale = $this->scaleFor($invoice);

            // ORCHESTRATOR RULING (root-cause of the 50.174 drift): reconstruct
            // $amount EXACTLY across the invoice's real lines before any
            // document-level duty is folded in.
            /** @phpstan-ignore-next-line argument.type */
            $allocation = $this->allocateAmountAcrossInvoiceLines($invoice, $amount, $scale);

            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'location_id' => $invoice->location_id,
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $creditNoteNumber,
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => $allocation['subtotal'],
                'tax_amount' => $allocation['taxAmount'],
                'total' => $amount,
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
            // (bug #2). Prorated lines give revenue/VAT/AR (and restock) real
            // data and keep the reversal balanced.
            $this->materializeLinesFromAllocation($creditNote, $allocation['lines']);

            // Fold the credit note's own document-level duty (e.g. the 0.600
            // TND STAMP_CREDIT_NOTE) so the Draft's total already equals its
            // post-confirmation total (ticket finding 3).
            $this->foldDocumentLevelTaxes($creditNote, $allocation['subtotal'], $allocation['taxAmount'], $scale);

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

            $creditNoteNumber = $this->numberingService->generateNumber($tenantId, $invoice->company_id, DocumentType::CreditNote);

            $scale = $this->scaleFor($invoice);

            // Calculate totals from selected lines
            /** @var numeric-string $subtotal */
            $subtotal = '0';
            /** @var numeric-string $taxAmount */
            $taxAmount = '0';

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

                // Calculate line totals
                $lineSubtotal = bcmul($quantity, $invoiceLine->unit_price, $scale);
                $taxRate = (string) ($invoiceLine->tax_rate ?? '0');
                // precision-ok: 6 = high-precision rate-fraction intermediate.
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 6), $scale);

                $subtotal = bcadd($subtotal, $lineSubtotal, $scale);
                $taxAmount = bcadd($taxAmount, $lineTax, $scale);

                $lineMap[$lineId] = [
                    'invoiceLine' => $invoiceLine,
                    'quantity' => $quantity,
                ];
            }

            $total = bcadd($subtotal, $taxAmount, $scale);

            // Validation: Total credit notes cannot exceed invoice total
            /** @var numeric-string $totalCreditNotes */
            $totalCreditNotes = $invoice->creditNotes()->sum('total');
            // precision-ok: 4 = canonical amount comparison scale (pre-existing guard).
            /** @phpstan-ignore-next-line argument.type */
            $remainingAmount = bcsub($invoice->total, (string) $totalCreditNotes, 4);

            // precision-ok: 4 = canonical amount comparison scale (pre-existing guard).
            if (bccomp($total, $remainingAmount, 4) > 0) {
                throw new \InvalidArgumentException('Total credit notes would exceed invoice total');
            }

            // Create credit note document
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'partner_id' => $invoice->partner_id,
                'location_id' => $invoice->location_id,
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $creditNoteNumber,
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
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

            // Create credit note lines
            $lineNumber = 1;
            foreach ($lineMap as $lineId => $data) {
                /** @var DocumentLine $invoiceLine */
                $invoiceLine = $data['invoiceLine'];
                /** @var numeric-string $quantity */
                $quantity = $data['quantity'];

                $lineTotal = bcmul($quantity, $invoiceLine->unit_price, $scale);

                DocumentLine::create([
                    'document_id' => $creditNote->id,
                    'product_id' => $invoiceLine->product_id,
                    'line_number' => $lineNumber,
                    'description' => $invoiceLine->description,
                    'quantity' => $quantity,
                    'unit_price' => $invoiceLine->unit_price,
                    'discount_percent' => $invoiceLine->discount_percent,
                    'discount_amount' => $invoiceLine->discount_amount,
                    'tax_rate' => $invoiceLine->tax_rate,
                    'line_total' => $lineTotal,
                    'notes' => $invoiceLine->notes,
                    'designation_default_snapshot' => $invoiceLine->designation_default_snapshot,
                ]);

                $lineNumber++;
            }

            // Fold the credit note's own document-level duty (e.g. the 0.600
            // TND STAMP_CREDIT_NOTE) so the Draft's total already equals its
            // post-confirmation total (ticket finding 3).
            $this->foldDocumentLevelTaxes($creditNote, $subtotal, $taxAmount, $scale);

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

            // Generate credit note number
            $creditNoteNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::CreditNote);

            $currency = (string) ($partner->currency ?? 'TND');
            $scale = $currency !== ''
                ? $this->scaleResolver->getScale($currency)
                : $this->scaleResolver->getScaleSafe(null, 3);

            // Calculate totals from provided lines
            /** @var numeric-string $subtotal */
            $subtotal = '0';
            /** @var numeric-string $taxAmount */
            $taxAmount = '0';

            foreach ($lines as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];
                $taxRate = (string) $line['tax_rate'];

                // Validate quantity and price
                // precision-ok: 4 = canonical quantity comparison scale.
                if (bccomp($quantity, '0', 4) <= 0) {
                    throw new \InvalidArgumentException('Line quantity must be greater than zero');
                }

                // precision-ok: 4 = canonical quantity/amount comparison scale.
                if (bccomp($unitPrice, '0', 4) < 0) {
                    throw new \InvalidArgumentException('Line unit price cannot be negative');
                }

                // Calculate line totals
                $lineSubtotal = bcmul($quantity, $unitPrice, $scale);
                // precision-ok: 6 = high-precision rate-fraction intermediate.
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 6), $scale);

                $subtotal = bcadd($subtotal, $lineSubtotal, $scale);
                $taxAmount = bcadd($taxAmount, $lineTax, $scale);
            }

            $total = bcadd($subtotal, $taxAmount, $scale);

            // Create standalone credit note document (no source_document_id)
            $creditNote = Document::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'location_id' => null, // No location for standalone credit notes
                'type' => DocumentType::CreditNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::CreditNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $creditNoteNumber,
                'document_date' => now(),
                'currency' => $currency,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
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

            // Fold the credit note's own document-level duty (e.g. the 0.600
            // TND STAMP_CREDIT_NOTE) so the Draft's total already equals its
            // post-confirmation total (ticket finding 3).
            $this->foldDocumentLevelTaxes($creditNote, $subtotal, $taxAmount, $scale);

            return $creditNote;
        });
    }

    /**
     * Allocate a posted credit note against its source invoice
     *
     * This reduces the invoice's balance_due and may change its payment status to Paid.
     * Called automatically when a credit note is posted.
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

            // Create the allocation
            $allocation = CreditNoteAllocation::create([
                'credit_note_id' => $creditNote->id,
                'invoice_id' => $invoice->id,
                'amount' => $creditNote->total,
                'allocated_by' => $allocatedBy,
            ]);

            // Note: balance_due will be updated automatically by the PostgreSQL trigger

            return $allocation;
        });
    }
}
