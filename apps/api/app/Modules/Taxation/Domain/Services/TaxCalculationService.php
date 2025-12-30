<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Domain\Enums\TaxType;

class TaxCalculationService
{
    public function __construct(
        private StampDutyService $stampDutyService
    ) {}

    /**
     * Calculate complete tax breakdown for a document
     */
    public function calculateDocumentTaxes(Document $document): DocumentTaxCalculationResult
    {
        // 1. Calculate line-item taxes (percentage-based VAT)
        $lineTaxAmount = '0.00';
        $lineTaxDetails = [];

        foreach ($document->lines as $line) {
            $lineSubtotal = bcmul((string) $line->quantity, (string) $line->unit_price, 2);
            $taxRate = $line->tax_rate ?? '0';

            if (bccomp($taxRate, '0', 2) > 0) {
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), 2);
                $lineTaxAmount = bcadd($lineTaxAmount, $lineTax, 2);

                $lineTaxDetails[] = new TaxDetail(
                    taxType: TaxType::Percentage,
                    taxName: "TVA {$taxRate}%",
                    taxBase: $lineSubtotal,
                    taxRate: $taxRate,
                    taxAmount: $lineTax,
                    isStampDuty: false
                );
            }
        }

        // 2. Calculate stamp duty (fixed amount, only on POSTED invoices)
        $stampDutyAmount = '0.000';
        $stampDutyDetail = null;

        if ($this->shouldApplyStampDuty($document)) {
            $stampDuty = $this->stampDutyService->calculateStampDuty(
                $document->company->country_code,
                $document->type,
                $document->fiscal_category,
                $document->document_date
            );

            if ($stampDuty !== null) {
                $stampDutyAmount = $stampDuty->amount;
                $stampDutyDetail = new TaxDetail(
                    taxType: TaxType::FixedAmount,
                    taxName: $stampDuty->name,
                    taxBase: null,
                    taxRate: null,
                    taxAmount: $stampDutyAmount,
                    isStampDuty: true
                );
            }
        }

        // 3. Calculate total
        $subtotal = $document->subtotal ?? '0.00';
        $discount = $document->discount_amount ?? '0.00';

        // total = (subtotal - discount) + line_tax + stamp_duty
        $total = bcadd(
            bcsub((string) $subtotal, (string) $discount, 2),
            bcadd($lineTaxAmount, $stampDutyAmount, 3),
            2
        );

        // Combine tax details
        $allTaxDetails = $lineTaxDetails;
        if ($stampDutyDetail !== null) {
            $allTaxDetails[] = $stampDutyDetail;
        }

        return new DocumentTaxCalculationResult(
            lineTaxAmount: $lineTaxAmount,
            stampDutyAmount: $stampDutyAmount,
            totalTaxAmount: bcadd($lineTaxAmount, $stampDutyAmount, 2),
            total: $total,
            taxDetails: $allTaxDetails
        );
    }

    /**
     * Determine if stamp duty should be applied to a document
     *
     * Rules:
     * - Only POSTED invoices
     * - NOT on quotes, orders, drafts
     * - NOT on credit notes
     * - Must be a fiscal document (not NON_FISCAL)
     */
    private function shouldApplyStampDuty(Document $document): bool
    {
        // Only posted documents
        if ($document->status !== DocumentStatus::Posted) {
            return false;
        }

        // Only invoices (not credit notes, quotes, orders, etc.)
        if ($document->type !== DocumentType::Invoice) {
            return false;
        }

        // Must be fiscal
        if (!$document->fiscal_category->isFiscal()) {
            return false;
        }

        return true;
    }
}

/**
 * Value object for individual tax detail
 */
readonly class TaxDetail
{
    public function __construct(
        public TaxType $taxType,
        public string $taxName,
        public ?string $taxBase,
        public ?string $taxRate,
        public string $taxAmount,
        public bool $isStampDuty
    ) {}
}

/**
 * Value object for complete document tax calculation result
 */
readonly class DocumentTaxCalculationResult
{
    /**
     * @param  list<TaxDetail>  $taxDetails
     */
    public function __construct(
        public string $lineTaxAmount,
        public string $stampDutyAmount,
        public string $totalTaxAmount,
        public string $total,
        public array $taxDetails
    ) {}
}
