<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\DTOs\CalculatedTax;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;

class TaxCalculationService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Resolve the monetary scale for a document.
     *
     * Threads the document's own currency into the resolver so the scale never
     * depends on a bound CompanyContext. The WorkOrder→Invoice generation path
     * runs outside an HTTP request (no bound context); passing an explicit
     * currency is both context-safe AND fiscally correct (EUR→2, TND→3).
     *
     * If the document has no currency set, fall back to the bound CompanyContext
     * (so the company's country scale is honoured), and finally to scale 3 (safe
     * maximum) when no context is bound either. Note: an empty currency string
     * must NOT be passed through to the resolver, because the ISO 4217 map would
     * silently return the default scale 2 instead of the company's true scale.
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
     * Calculate all applicable taxes for a document
     */
    public function calculateDocumentTaxes(Document $document): TaxCalculationResult
    {
        $company = $document->company;
        $partner = $document->partner;
        $documentType = $document->fiscal_category?->value ?? $document->type->value;
        $countryCode = $company->country_code;

        // Resolve the rate table as of the document's own date, so a back-dated
        // or future document gets the rate in force on that date (not just the
        // currently-active row). documents.document_date is NOT NULL.
        $documentDate = $document->document_date->toDateString();

        $scale = $this->scaleFor($document);

        // Get line items subtotal (before any taxes)
        /** @var numeric-string $subtotal */
        $subtotal = $this->calculateSubtotal($document, $scale);

        // Check for partner exemption status
        $exemptionInfo = $this->getExemptionInfo($partner);

        // Get applicable taxes for this document type, ordered by sequence
        $applicableTaxes = TaxConfiguration::query()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->forDocumentType($documentType)
            ->effectiveOn($documentDate)
            ->ordered()
            ->get();

        // Calculate each tax
        $calculatedTaxes = [];
        /** @var numeric-string $runningTaxTotal */
        $runningTaxTotal = '0';
        /** @var numeric-string $lineItemsTaxTotal */
        $lineItemsTaxTotal = '0';
        /** @var numeric-string $documentTaxTotal */
        $documentTaxTotal = '0';

        // STEP 1: Calculate line-item taxes (only once per unique rate, not per config)
        // Group lines by their tax rate to find which rates are actually used
        $linesByRate = $document->lines->groupBy('tax_rate');

        // Pass 1: pre-discount base per rate bucket. Each line is truncated
        // to the CURRENCY scale individually, then summed (bcadd of
        // already-scaled values is lossless) -- the same method
        // calculateSubtotal() uses, NOT a scale+1 accumulator rounded once.
        // This guarantees Σ(preDiscountBase) across every bucket is
        // byte-identical to the document's pre-discount line total, so the
        // post-discount bucket bases (below) sum EXACTLY to $subtotal —
        // 2026-08-03 gate V4 (a scale+1-then-round-once accumulator drifts
        // under sub-scale truncation; verified lossless by the gate probe).
        //
        // A rate group is included whenever the line carries an EXPLICIT
        // rate, including an explicit 0% (exempt) rate — 2026-08-03 gate V3:
        // exempt turnover must still land in the declaration's base_0
        // bracket. Only a genuinely absent tax_rate (NULL — no rate was
        // ever set on the line) is skipped; that is not a taxable/exempt
        // supply the declaration can classify.
        // Each bucket ALSO keeps the pre-existing high-precision tax
        // accumulator (scale+1 per-line tax, summed, rounded ONCE) —
        // TaxCalculationScalingTest's gold case (1000 sub-scale lines whose
        // individual net truncates to 0.000 but whose true tax total is
        // 0.361): truncating the BASE per line first (as the preDiscountBase
        // above does, for V4's declared-base invariant) and then multiplying
        // by rate would reintroduce that catastrophic truncation for tax
        // itself. So the undiscounted tax stays on the untruncated
        // accumulator; only a bucket that actually absorbs a discount share
        // (below) is recomputed from its (currency-scale) discounted base.
        /** @var array<string, array{rateFraction: numeric-string, lines: Collection<int, DocumentLine>, preDiscountBase: numeric-string, taxAccumulator: numeric-string}> $rateGroups */
        $rateGroups = [];
        /** @var array<string, numeric-string> $preDiscountBaseByRate */
        $preDiscountBaseByRate = [];
        /** @var numeric-string $totalPreDiscountBase */
        $totalPreDiscountBase = '0';

        foreach ($linesByRate as $rate => $linesWithRate) {
            $rateStr = (string) $rate;
            if ($rateStr === '') {
                continue; // No tax_rate at all (NULL) — not classifiable.
            }

            /** @var numeric-string $rateFraction */
            $rateFraction = bcdiv($rateStr, '100', 6);
            /** @var numeric-string $preDiscountBase */
            $preDiscountBase = '0';
            /** @var numeric-string $taxAccumulator */
            $taxAccumulator = '0';
            foreach ($linesWithRate as $line) {
                // Tax base is the NET line (gross − line discount). Money =
                // qty(scale 4) × unitPrice with the discount applied; a
                // scale+1 intermediate keeps the 4th quantity decimal alive
                // through the multiply.
                $lineSubtotal = $line->calculateTotal($scale + 1);
                // preDiscountBase: each line rounded to the currency
                // boundary before summing (matches calculateSubtotal()).
                $preDiscountBase = bcadd($preDiscountBase, CurrencyScale::bcformat($lineSubtotal, $scale), $scale);
                // taxAccumulator: each line's tax kept at scale+1 and summed
                // UNROUNDED — rounded once, below, at the currency boundary.
                $lineTax = bcmul($lineSubtotal, $rateFraction, $scale + 1);
                $taxAccumulator = bcadd($taxAccumulator, $lineTax, $scale + 1);
            }

            $rateGroups[$rateStr] = [
                'rateFraction' => $rateFraction,
                'lines' => $linesWithRate,
                'preDiscountBase' => $preDiscountBase,
                'taxAccumulator' => $taxAccumulator,
            ];
            $preDiscountBaseByRate[$rateStr] = $preDiscountBase;
            $totalPreDiscountBase = bcadd($totalPreDiscountBase, $preDiscountBase, $scale);
        }

        // V1 (2026-08-03 gate, P0 regression fix): a document-level discount
        // must reduce the base EVERY rate bucket is taxed on, not just the
        // aggregate subtotal — otherwise VAT is charged on the pre-discount
        // base (legally wrong) and, worse, the snapshotted tax_base no
        // longer even ties to the discounted subtotal. Prorate
        // $document->discount_amount across buckets proportional to each
        // bucket's pre-discount share, using a largest-remainder allocation
        // at currency scale so Σ(shares) == discount_amount EXACTLY.
        /** @var numeric-string $discountAmount */
        $discountAmount = (string) ($document->discount_amount ?? '0');
        $discountShares = $this->prorateDiscount($preDiscountBaseByRate, $discountAmount, $totalPreDiscountBase, $scale);

        foreach ($rateGroups as $rateStr => $group) {
            $linesWithRate = $group['lines'];

            // Find the ONE tax configuration that matches this rate
            $matchingConfig = $applicableTaxes->first(function ($cfg) use ($rateStr) {
                /** @var numeric-string $cfgRate */
                $cfgRate = (string) $cfg->percentage_rate;
                /** @var numeric-string $lineRate */
                $lineRate = $rateStr;

                return $cfg->applies_to === TaxApplicationLevel::LineItems
                    && bccomp($cfgRate, $lineRate, 2) === 0;
            });

            // The base ACTUALLY taxed at this rate: the bucket's pre-discount
            // net, minus this bucket's proportional share of the
            // document-level discount.
            $discountShare = $discountShares[$rateStr] ?? '0';
            $rateBase = bcsub($group['preDiscountBase'], $discountShare, $scale);

            if (bccomp($discountShare, '0', $scale) === 0) {
                // No discount landed on this bucket: keep the undiscounted,
                // high-precision tax (scale+1 per-line accumulation, rounded
                // ONCE) so pathological sub-scale-heavy documents
                // (TaxCalculationScalingTest) are unaffected by V4's
                // currency-scale base truncation.
                $taxAmount = CurrencyScale::bcformat($group['taxAccumulator'], $scale);
            } else {
                // A discount was prorated into this bucket: recompute tax as
                // a single clean multiply of the (now currency-scale)
                // discounted base by the already-resolved rate fraction, so
                // base × rate == amount holds as an exact identity
                // (2026-08-03 gate V1: the pre-fix code let base and amount
                // drift apart under a discount, making the defect internally
                // "consistent" and invisible -- both stayed pre-discount and
                // therefore still agreed with each other).
                $taxAmount = CurrencyScale::bcformat(bcmul($rateBase, $group['rateFraction'], $scale + 1), $scale);
            }

            $lineItemsTaxTotal = bcadd($lineItemsTaxTotal, $taxAmount, $scale);

            if ($matchingConfig) {
                $calculatedTaxes[] = new CalculatedTax(
                    configurationId: $matchingConfig->id,
                    code: $matchingConfig->code ?? '',
                    name: $matchingConfig->name,
                    type: $matchingConfig->tax_type,
                    rate: $matchingConfig->percentage_rate,
                    fixedAmount: $matchingConfig->fixed_amount,
                    base: $rateBase,
                    amount: $taxAmount,
                    sequenceOrder: $matchingConfig->sequence_order,
                    isStampDuty: $matchingConfig->is_stamp_duty,
                    isRecoverable: $this->determineRecoverability($matchingConfig, $company),
                    appliesTo: $matchingConfig->applies_to,
                );
            } else {
                // ORCHESTRATOR RULING (2026-08-02, documents-defects lane
                // defect 3): an explicitly-supplied line rate must NEVER be
                // silently zeroed. No active TaxConfiguration row matched
                // this rate for this document's fiscal category/country/date
                // -- either because the rate is genuinely unconfigured, or
                // because this document type never carries a matching token
                // (e.g. a NonFiscal quote/sales order; TN/FR's seeded rows
                // never list a NonFiscal token in applicable_document_types).
                // Honour the line's own rate directly (the same formula
                // InvoiceController::store() and CopiesDocumentData::
                // recalculateTotals() already use for drafts) instead of
                // contributing zero, so confirm() stays consistent with the
                // draft it is confirming. This also covers an explicit 0%
                // rate with no matching TVA_EXEMPT-style config (V3): the
                // base-only row is still emitted, at zero tax.
                $calculatedTaxes[] = new CalculatedTax(
                    configurationId: '',
                    code: 'UNCONFIGURED',
                    name: "VAT {$rateStr}%",
                    type: TaxType::Percentage,
                    rate: $rateStr,
                    fixedAmount: null,
                    base: $rateBase,
                    amount: $taxAmount,
                    sequenceOrder: 0,
                    isStampDuty: false,
                    isRecoverable: $company->tax_status !== CompanyTaxStatus::NON_REGISTERED,
                    appliesTo: TaxApplicationLevel::LineItems,
                );
            }

            $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, $scale);
        }

        // STEP 2: Calculate stamp duty at the document level. The result's
        // documentTaxTotal is persisted and posted as stamp_duty_amount, so a
        // generic DOCUMENT_TOTAL row must never enter this named money lane.
        foreach ($applicableTaxes as $taxConfig) {
            if ($taxConfig->applies_to === TaxApplicationLevel::DocumentTotal
                && $taxConfig->is_stamp_duty
            ) {
                $taxBase = $subtotal;
                /** @var numeric-string $taxAmount */
                $taxAmount = $taxConfig->calculateAmount($taxBase, $runningTaxTotal);
                $documentTaxTotal = bcadd($documentTaxTotal, $taxAmount, $scale);

                $calculatedTaxes[] = new CalculatedTax(
                    configurationId: $taxConfig->id,
                    code: $taxConfig->code ?? '',
                    name: $taxConfig->name,
                    type: $taxConfig->tax_type,
                    rate: $taxConfig->percentage_rate,
                    fixedAmount: $taxConfig->fixed_amount,
                    base: $taxBase,
                    amount: $taxAmount,
                    sequenceOrder: $taxConfig->sequence_order,
                    isStampDuty: $taxConfig->is_stamp_duty,
                    isRecoverable: $this->determineRecoverability($taxConfig, $company),
                    appliesTo: $taxConfig->applies_to,
                );

                // Update running total for compound taxes
                $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, $scale);
            }
        }

        $totalTax = bcadd($lineItemsTaxTotal, $documentTaxTotal, $scale);
        $total = bcadd($subtotal, $totalTax, $scale);

        return new TaxCalculationResult(
            taxes: $calculatedTaxes,
            subtotal: $subtotal,
            lineItemsTaxTotal: $lineItemsTaxTotal,
            documentTaxTotal: $documentTaxTotal,
            totalTax: $totalTax,
            total: $total,
            exemptionInfo: $exemptionInfo,
        );
    }

    /**
     * Calculate line items subtotal
     */
    private function calculateSubtotal(Document $document, int $scale): string
    {
        $subtotal = '0';

        foreach ($document->lines as $line) {
            // NET line (gross − line discount) is the taxable base. Money =
            // qty(scale 4) × unitPrice with the discount applied; a scale+1
            // intermediate keeps the 4th quantity decimal alive through the
            // multiply, then round each line to the currency boundary. This
            // keeps the subtotal coherent with DocumentTotalsCalculator::
            // recalculate, which sums per-line calculateTotal(scale) values.
            // For a line with no discount this is byte-identical to the old
            // bcmul(qty, unitPrice, scale+1) intermediate.
            $lineTotal = CurrencyScale::bcformat(
                $line->calculateTotal($scale + 1),
                $scale,
            );
            $subtotal = bcadd($subtotal, $lineTotal, $scale);
        }

        // Apply document-level discount if any
        if ($document->discount_amount) {
            $subtotal = bcsub($subtotal, (string) $document->discount_amount, $scale);
        }

        return $subtotal;
    }

    /**
     * Prorate a document-level discount across rate buckets, proportional to
     * each bucket's pre-discount base share, using a largest-remainder
     * allocation at currency scale.
     *
     * A naive proportional divide (discount × bucketBase / totalBase,
     * truncated per bucket) loses up to (bucketCount − 1) smallest currency
     * units to truncation, so Σ(shares) would fall short of $discountAmount
     * and Σ(postDiscountBase) would NOT tie to the discounted subtotal. This
     * floors every bucket's raw share first, then hands the leftover
     * smallest-currency-units — one each — to the buckets with the largest
     * fractional remainder, until the shares sum to $discountAmount exactly.
     *
     * @param  array<string, numeric-string>  $preDiscountBaseByRate
     * @param  numeric-string  $discountAmount
     * @param  numeric-string  $totalPreDiscountBase
     * @return array<string, numeric-string> Keyed by the same rate string as $preDiscountBaseByRate.
     */
    private function prorateDiscount(
        array $preDiscountBaseByRate,
        string $discountAmount,
        string $totalPreDiscountBase,
        int $scale,
    ): array {
        /** @var array<string, numeric-string> $shares */
        $shares = [];
        foreach (array_keys($preDiscountBaseByRate) as $rateStr) {
            $shares[$rateStr] = '0';
        }

        if (bccomp($discountAmount, '0', $scale) <= 0 || bccomp($totalPreDiscountBase, '0', $scale) <= 0) {
            return $shares;
        }

        /** @var numeric-string $unit */
        $unit = bcdiv('1', bcpow('10', (string) $scale), $scale);
        /** @var numeric-string $flooredTotal */
        $flooredTotal = '0';
        /** @var array<string, numeric-string> $remainders */
        $remainders = [];

        foreach ($preDiscountBaseByRate as $rateStr => $base) {
            // High-precision proportional share, then truncate to the
            // currency scale (floor, since every operand is non-negative).
            /** @var numeric-string $rawShare */
            $rawShare = bcdiv(
                bcmul($discountAmount, $base, $scale + 4),
                $totalPreDiscountBase,
                $scale + 4,
            );
            $floored = CurrencyScale::bcformat($rawShare, $scale);
            $shares[$rateStr] = $floored;
            $flooredTotal = bcadd($flooredTotal, $floored, $scale);
            $remainders[$rateStr] = bcsub($rawShare, $floored, $scale + 4);
        }

        /** @var numeric-string $deficitAmount */
        $deficitAmount = bcsub($discountAmount, $flooredTotal, $scale);
        // Not a currency amount -- a whole COUNT of smallest-currency-units
        // still owed to some bucket after flooring; 0 decimal places is
        // correct by definition, not a monetary scale.
        // precision-ok: unit-count division, not a monetary amount
        $deficitUnits = (int) bcdiv($deficitAmount, $unit, 0);

        if ($deficitUnits > 0) {
            uasort($remainders, static fn (string $a, string $b): int => bccomp($b, $a, $scale + 4));
            $i = 0;
            foreach (array_keys($remainders) as $rateStr) {
                if ($i >= $deficitUnits) {
                    break;
                }
                $shares[$rateStr] = bcadd($shares[$rateStr], $unit, $scale);
                $i++;
            }
        }

        return $shares;
    }

    /**
     * Determine if a tax is recoverable for the company
     *
     * Business Logic:
     * 1. Stamp duties are NEVER recoverable (they always add to product cost)
     * 2. If tax configuration marks it as non-recoverable, it's not recoverable
     * 3. VAT/recoverable taxes are only recoverable for VAT-registered companies
     * 4. Non-registered companies cannot recover any tax (all taxes add to product cost)
     */
    private function determineRecoverability(TaxConfiguration $taxConfig, Company $company): bool
    {
        // Stamp duties are NEVER recoverable
        if ($taxConfig->is_stamp_duty) {
            return false;
        }

        // If tax is configured as non-recoverable, it's not recoverable
        if (! $taxConfig->is_recoverable) {
            return false;
        }

        // VAT is recoverable ONLY for VAT-registered companies
        if ($company->tax_status === CompanyTaxStatus::NON_REGISTERED) {
            return false;
        }

        return true;
    }

    /**
     * Get partner exemption information for UI
     */
    /**
     * @return array<string, mixed>|null
     */
    private function getExemptionInfo(?Partner $partner): ?array
    {
        if (! $partner || $partner->tax_status !== PartnerTaxStatus::EXEMPT) {
            return null;
        }

        return [
            'status' => 'EXEMPT',
            'reason' => $partner->tax_exemption_reason,
            'hasValidCertificate' => $partner->hasValidTaxExemption(),
            'warnings' => $partner->getTaxExemptionWarnings(),
        ];
    }

    /**
     * Store tax details when document is finalized (immutable snapshot)
     */
    public function snapshotTaxDetails(Document $document, TaxCalculationResult $result): void
    {
        // Remove any existing details (for drafts being re-finalized)
        DocumentTaxDetail::where('document_id', $document->id)->delete();

        // Create immutable snapshot
        foreach ($result->taxes as $tax) {
            DocumentTaxDetail::create([
                'document_id' => $document->id,
                'sequence_order' => $tax->sequenceOrder,
                'tax_code' => $tax->code,
                'tax_name' => $tax->name,
                'tax_type' => $tax->type->value,
                'tax_rate' => $tax->rate ?? '0',
                'tax_fixed_amount' => $tax->fixedAmount ?? '0',
                'tax_base' => $tax->base,
                'tax_amount' => $tax->amount,
                'is_stamp_duty' => $tax->isStampDuty,
                'created_at' => now(),
            ]);
        }
    }
}
