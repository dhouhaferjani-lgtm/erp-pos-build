<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
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

        foreach ($linesByRate as $rate => $linesWithRate) {
            $rateStr = (string) $rate;
            if ($rateStr === '' || $rateStr === '0' || $rateStr === '0.00') {
                continue; // Skip lines with no tax
            }

            // Find the ONE tax configuration that matches this rate
            $matchingConfig = $applicableTaxes->first(function ($cfg) use ($rate) {
                /** @var numeric-string $cfgRate */
                $cfgRate = (string) $cfg->percentage_rate;
                /** @var numeric-string $lineRate */
                $lineRate = (string) $rate;

                return $cfg->applies_to === TaxApplicationLevel::LineItems
                    && bccomp($cfgRate, $lineRate, 2) === 0;
            });

            // Calculate tax for all lines with this rate.
            //
            // Precision: quantity is stored at scale 4. bcmul(qty, unitPrice)
            // is MONEY, so the qty×price intermediate and the ×rate
            // intermediate both run at scale()+1 — keeping the 4th quantity
            // decimal alive through both multiplies. The per-rate tax is
            // accumulated at scale()+1 and rounded ONCE at the currency
            // boundary, instead of truncating each line's tax to the
            // boundary scale first (which discarded sub-boundary fractions).
            //
            // This runs REGARDLESS of whether a TaxConfiguration row matched
            // (see the ORCHESTRATOR RULING below) -- an explicit line rate
            // is always honoured at the currency-rounding level identical to
            // the matched-config path.
            /** @var numeric-string $rateFraction */
            $rateFraction = bcdiv((string) $rate, '100', 6);
            /** @var numeric-string $taxAccumulator */
            $taxAccumulator = '0';
            foreach ($linesWithRate as $line) {
                // Tax base is the NET line (gross − line discount), computed
                // at scale+1 to keep the 4th quantity decimal alive. For a
                // line with no discount calculateTotal() == bcmul(qty, price,
                // scale+1), so undiscounted lines are byte-identical.
                $lineSubtotal = $line->calculateTotal($scale + 1);
                $lineTax = bcmul($lineSubtotal, $rateFraction, $scale + 1);
                $taxAccumulator = bcadd($taxAccumulator, $lineTax, $scale + 1);
            }
            $taxAmount = CurrencyScale::bcformat($taxAccumulator, $scale);

            $lineItemsTaxTotal = bcadd($lineItemsTaxTotal, $taxAmount, $scale);

            if ($matchingConfig) {
                $calculatedTaxes[] = new CalculatedTax(
                    configurationId: $matchingConfig->id,
                    code: $matchingConfig->code ?? '',
                    name: $matchingConfig->name,
                    type: $matchingConfig->tax_type,
                    rate: $matchingConfig->percentage_rate,
                    fixedAmount: $matchingConfig->fixed_amount,
                    base: $subtotal,
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
                // draft it is confirming.
                $calculatedTaxes[] = new CalculatedTax(
                    configurationId: '',
                    code: 'UNCONFIGURED',
                    name: "VAT {$rateStr}%",
                    type: TaxType::Percentage,
                    rate: $rateStr,
                    fixedAmount: null,
                    base: $subtotal,
                    amount: $taxAmount,
                    sequenceOrder: 0,
                    isStampDuty: false,
                    isRecoverable: $company->tax_status !== CompanyTaxStatus::NON_REGISTERED,
                    appliesTo: TaxApplicationLevel::LineItems,
                );
            }

            $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, $scale);
        }

        // STEP 2: Calculate document-level taxes (these can stack)
        foreach ($applicableTaxes as $taxConfig) {
            if ($taxConfig->applies_to === TaxApplicationLevel::DocumentTotal) {
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
                'created_at' => now(),
            ]);
        }
    }
}
