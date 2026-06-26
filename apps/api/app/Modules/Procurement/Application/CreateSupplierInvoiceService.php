<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

/**
 * Creates a supplier invoice document with canonical totals.
 *
 * Moves all monetary/tax computation out of the HTTP controller so the
 * presentation layer stays validation+delegation only. Uses the same
 * TaxCalculationService used by the rest of the document system to resolve
 * stamp_duty_amount (timbre fiscal) so the §3 GL legs are fully populated.
 *
 * Precision contract (docs/architecture/precision-contract.md):
 *   - Per-line subtotal  : bcmul(qty, unitPrice, scale+1) then bcformat to scale
 *   - Per-line VAT       : bcmul(lineSubtotal, rateFraction, scale+1) then bcformat to scale
 *   - Stamp duty         : resolved by TaxCalculationService (fixed per TaxConfiguration)
 *   - Invariant          : total = subtotal + Σrecoverable_vat + stamp_duty
 */
final class CreateSupplierInvoiceService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly SupplierInvoiceMatcher $matcher,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, string $tenantId, string $companyId): Document
    {
        /** @var string $currency */
        $currency = $validated['currency'];
        $scale = $this->scaleResolver->getScaleSafe($currency, 3);

        /** @var array<int, array<string, mixed>> $lines */
        $lines = $validated['lines'];

        return DB::transaction(function () use ($tenantId, $companyId, $validated, $lines, $currency, $scale): Document {
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::SupplierInvoice);

            // ── Pass 1: compute per-line amounts at scale+1 before rounding ─────
            $subtotal = '0';
            $lineTaxTotal = '0';

            /** @var array<int, array{qty: string, unitPrice: string, vatRate: string, lineSubtotal: string, lineTax: string, sourceLineId: string}> $lineData */
            $lineData = [];

            foreach ($lines as $lineInput) {
                /** @var numeric-string $qty */
                $qty = (string) $lineInput['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineInput['unit_price'];
                /** @var numeric-string $vatRate */
                $vatRate = (string) $lineInput['vat_rate'];

                // Intermediate at scale+1; truncate once at currency boundary.
                /** @var numeric-string $lineSubtotal */
                $lineSubtotal = CurrencyScale::bcformat(bcmul($qty, $unitPrice, $scale + 1), $scale);
                /** @var numeric-string $rateFraction */
                $rateFraction = bcdiv($vatRate, '100', 6);
                /** @var numeric-string $lineTax */
                $lineTax = CurrencyScale::bcformat(bcmul($lineSubtotal, $rateFraction, $scale + 1), $scale);

                $subtotal = bcadd($subtotal, $lineSubtotal, $scale);
                $lineTaxTotal = bcadd($lineTaxTotal, $lineTax, $scale);

                $lineData[] = [
                    'qty' => $qty,
                    'unitPrice' => $unitPrice,
                    'vatRate' => $vatRate,
                    'lineSubtotal' => $lineSubtotal,
                    'lineTax' => $lineTax,
                    'sourceLineId' => (string) $lineInput['source_line_id'],
                ];
            }

            // ── Create document with placeholder totals (stamp_duty resolved below) ─
            $document = Document::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $validated['partner_id'],
                'source_document_id' => $validated['source_document_id'],
                'type' => DocumentType::SupplierInvoice,
                'fiscal_category' => FiscalCategory::NonFiscal,
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'document_number' => $documentNumber,
                'document_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'line_tax_amount' => $lineTaxTotal,
                'stamp_duty_amount' => '0.000',
                'tax_amount' => $lineTaxTotal,
                'total' => bcadd($subtotal, $lineTaxTotal, $scale),
                'external_document_number' => $validated['supplier_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'match_status' => SupplierInvoiceMatchStatus::Unmatched,
            ]);

            // ── Create lines so TaxCalculationService can group by tax_rate ──────
            foreach ($lineData as $idx => $ld) {
                /** @var DocumentLine|null $poLine */
                $poLine = DocumentLine::find($ld['sourceLineId']);
                $description = $poLine !== null ? $poLine->description : '';

                DocumentLine::create([
                    'document_id' => $document->id,
                    'line_number' => $idx + 1,
                    'description' => $description,
                    'quantity' => $ld['qty'],
                    'quantity_delivered' => '0.0000',
                    'quantity_received' => '0.0000',
                    'quantity_invoiced' => '0.0000',
                    'unit_price' => $ld['unitPrice'],
                    'tax_rate' => $ld['vatRate'],
                    'tax_amount' => $ld['lineTax'],
                    'tax_recoverable' => true,
                    'recoverable_tax_amount' => $ld['lineTax'],
                    'non_recoverable_tax_amount' => '0.000',
                    'line_total' => $ld['lineSubtotal'],
                    'allocated_costs' => '0.0000',
                    'source_line_id' => $ld['sourceLineId'],
                ]);
            }

            // ── Resolve stamp_duty via canonical TaxCalculationService ────────────
            // Must load company + partner + lines before calling calculateDocumentTaxes.
            $document->load(['company', 'partner', 'lines']);

            $taxResult = $this->taxCalculationService->calculateDocumentTaxes($document);

            // Persist stamp_duty and refresh so Eloquent's decimal:3 cast gives us
            // a numeric-string we can safely pass to bcadd (avoids bcadd(string) PHPStan).
            $document->update(['stamp_duty_amount' => $taxResult->documentTaxTotal]);
            $document->refresh();

            // Compute total from our own per-line amounts + resolved stamp duty so the
            // GL posting invariant (total = subtotal + Σrecoverable + stamp_duty) always
            // holds — even when no TaxConfiguration exists for this document type.
            /** @var numeric-string $stampDuty */
            $stampDuty = $document->stamp_duty_amount ?? '0';
            $taxAmount = bcadd($lineTaxTotal, $stampDuty, $scale);
            $total = bcadd($subtotal, $taxAmount, $scale);

            $document->update([
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            // ── Auto-match ───────────────────────────────────────────────────────
            $document->load('lines');
            $matchStatus = $this->matcher->match($document);
            $document->match_status = $matchStatus;
            $document->save();

            $document->load(['lines', 'partner', 'sourceDocument']);

            return $document;
        });
    }
}
