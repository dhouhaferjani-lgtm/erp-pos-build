<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Domain\DTOs\CalculatedTax;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\StackingBehavior;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;

class TaxCalculationService
{
    /**
     * Calculate all applicable taxes for a document
     */
    public function calculateDocumentTaxes(Document $document): TaxCalculationResult
    {
        $company = $document->company;
        $partner = $document->partner;
        $documentType = $document->type->value;
        $countryCode = $company->country_code;

        // Get line items subtotal (before any taxes)
        $subtotal = $this->calculateSubtotal($document);

        // Check for partner exemption status
        $exemptionInfo = $this->getExemptionInfo($partner);

        // Get applicable taxes for this document type, ordered by sequence
        $applicableTaxes = TaxConfiguration::query()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->forDocumentType($documentType)
            ->ordered()
            ->get();

        // Calculate each tax
        $calculatedTaxes = [];
        $runningTaxTotal = '0';
        $lineItemsTaxTotal = '0';
        $documentTaxTotal = '0';

        // STEP 1: Calculate line-item taxes (only once per unique rate, not per config)
        // Group lines by their tax rate to find which rates are actually used
        $linesByRate = $document->lines->groupBy('tax_rate');

        foreach ($linesByRate as $rate => $linesWithRate) {
            if ($rate === null || $rate === '' || $rate === '0' || $rate === '0.00') {
                continue; // Skip lines with no tax
            }

            // Find the ONE tax configuration that matches this rate
            $matchingConfig = $applicableTaxes->first(function ($cfg) use ($rate) {
                return $cfg->applies_to === TaxApplicationLevel::LineItems
                    && bccomp((string) $cfg->percentage_rate, (string) $rate, 2) === 0;
            });

            if ($matchingConfig) {
                // Calculate tax for all lines with this rate
                $taxAmount = '0';
                foreach ($linesWithRate as $line) {
                    $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, 3);
                    $lineTax = bcmul($lineSubtotal, bcdiv((string) $rate, '100', 6), 3);
                    $taxAmount = bcadd($taxAmount, $lineTax, 3);
                }

                $lineItemsTaxTotal = bcadd($lineItemsTaxTotal, $taxAmount, 3);

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

                $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, 3);
            }
        }

        // STEP 2: Calculate document-level taxes (these can stack)
        foreach ($applicableTaxes as $taxConfig) {
            if ($taxConfig->applies_to === TaxApplicationLevel::DocumentTotal) {
                $taxBase = $subtotal;
                $taxAmount = $taxConfig->calculateAmount($taxBase, $runningTaxTotal);
                $documentTaxTotal = bcadd($documentTaxTotal, $taxAmount, 3);

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
                $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, 3);
            }
        }

        $totalTax = bcadd($lineItemsTaxTotal, $documentTaxTotal, 3);
        $total = bcadd($subtotal, $totalTax, 3);

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
    private function calculateSubtotal(Document $document): string
    {
        $subtotal = '0';

        foreach ($document->lines as $line) {
            $lineTotal = bcmul((string) $line->quantity, (string) $line->unit_price, 3);
            $subtotal = bcadd($subtotal, $lineTotal, 3);
        }

        // Apply document-level discount if any
        if ($document->discount_amount) {
            $subtotal = bcsub($subtotal, (string) $document->discount_amount, 3);
        }

        return $subtotal;
    }

    /**
     * Calculate tax for line items (percentage-based taxes)
     */
    private function calculateLineItemsTax(
        Document $document,
        TaxConfiguration $taxConfig,
        string $previousTaxesTotal
    ): string {
        $totalTax = '0';

        foreach ($document->lines as $line) {
            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, 3);

            // Use line-specific rate if set, otherwise use config rate
            $rate = $line->tax_rate ?? $taxConfig->percentage_rate ?? '0';

            // Calculate base depending on stacking behavior
            $base = $lineSubtotal;
            if ($taxConfig->stacks_on === StackingBehavior::TOTAL_INCLUDING_PREVIOUS) {
                // Proportionally add previous taxes to this line's base
                // (simplified - assumes even distribution)
                $documentSubtotal = $this->calculateSubtotal($document);
                if (bccomp($documentSubtotal, '0', 3) > 0) {
                    $lineProportion = bcdiv($lineSubtotal, $documentSubtotal, 6);
                    $linePreviousTax = bcmul($previousTaxesTotal, $lineProportion, 3);
                    $base = bcadd($lineSubtotal, $linePreviousTax, 3);
                }
            }

            $lineTax = bcmul($base, bcdiv($rate, '100', 6), 3);
            $totalTax = bcadd($totalTax, $lineTax, 3);
        }

        return $totalTax;
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
    private function getExemptionInfo($partner): ?array
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
