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
use App\Modules\Product\Domain\Product;
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
 *   - Per-line subtotal  : bcmul(qty, unitPrice, scale+4) as high-precision string;
 *                          bcround ONCE at the invoice-leg boundary (half-up, never bcformat)
 *   - Per-line VAT       : bcmul(subtotalHp, rateFraction, scale+4) from the UN-truncated
 *                          subtotal; bcround ONCE at the invoice-leg boundary
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
        private readonly SupplierInvoiceMatchSnapshotService $matchSnapshotService,
        private readonly ProcurementPolicyResolver $policyResolver,
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
        /** @var list<string> $sourceDocumentIds */
        $sourceDocumentIds = array_values(array_map('strval', $validated['source_document_ids'] ?? []));
        if ($sourceDocumentIds === [] && isset($validated['source_document_id'])) {
            $sourceDocumentIds = [(string) $validated['source_document_id']];
        }
        $pendingReceipt = (bool) ($validated['pending_receipt'] ?? false);
        if ($pendingReceipt && ! $this->policyResolver->forCompany($companyId)->allowsInvoiceFirst()) {
            throw new \DomainException("Invoice-first procurement is disabled for company [{$companyId}].");
        }

        return DB::transaction(function () use ($tenantId, $companyId, $validated, $lines, $currency, $scale, $sourceDocumentIds, $pendingReceipt): Document {
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::SupplierInvoice);

            // ── Pass 1: compute per-line amounts at scale+1 before rounding ─────
            $subtotal = '0';
            $lineTaxTotal = '0';

            /** @var array<int, array{qty: numeric-string, unitPrice: numeric-string, vatRate: numeric-string, lineSubtotal: numeric-string, lineTax: numeric-string, sourceLineId: string|null, productId: string|null, variantId: string|null, isBonusLine: bool}> $lineData */
            $lineData = [];

            foreach ($lines as $lineInput) {
                /** @var numeric-string $qty */
                $qty = (string) $lineInput['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineInput['unit_price'];
                /** @var numeric-string $vatRate */
                $vatRate = (string) $lineInput['vat_rate'];

                // High-precision intermediate (scale+4) so no precision is lost before
                // rounding. VAT is computed from the UN-truncated subtotal so that
                // sub-millime precision is preserved through to the boundary.
                $working = $scale + 4;
                /** @var numeric-string $lineSubtotalHp */
                $lineSubtotalHp = bcmul($qty, $unitPrice, $working);
                /** @var numeric-string $lineSubtotal */
                $lineSubtotal = CurrencyScale::bcround($lineSubtotalHp, $scale);
                /** @var numeric-string $rateFraction */
                $rateFraction = bcdiv($vatRate, '100', 6);
                // VAT from the high-precision (un-rounded) subtotal; round ONCE at boundary.
                /** @var numeric-string $lineTaxHp */
                $lineTaxHp = bcmul($lineSubtotalHp, $rateFraction, $working);
                /** @var numeric-string $lineTax */
                $lineTax = CurrencyScale::bcround($lineTaxHp, $scale);

                $subtotal = bcadd($subtotal, $lineSubtotal, $scale);
                $lineTaxTotal = bcadd($lineTaxTotal, $lineTax, $scale);

                $lineData[] = [
                    'qty' => $qty,
                    'unitPrice' => $unitPrice,
                    'vatRate' => $vatRate,
                    'lineSubtotal' => $lineSubtotal,
                    'lineTax' => $lineTax,
                    'sourceLineId' => isset($lineInput['source_line_id']) ? (string) $lineInput['source_line_id'] : null,
                    'productId' => isset($lineInput['product_id']) ? (string) $lineInput['product_id'] : null,
                    'variantId' => isset($lineInput['variant_id']) ? (string) $lineInput['variant_id'] : null,
                    'isBonusLine' => (bool) ($lineInput['is_bonus_line'] ?? $lineInput['isBonusLine'] ?? false),
                ];
            }

            // ── Create document with placeholder totals (stamp_duty resolved below) ─
            $document = Document::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $validated['partner_id'],
                'source_document_id' => $sourceDocumentIds[0] ?? null,
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
                'payload' => [
                    'supplier_invoice' => [
                        'source_document_ids' => $sourceDocumentIds,
                        'pending_receipt' => $pendingReceipt,
                    ],
                ],
                'match_status' => SupplierInvoiceMatchStatus::Unmatched,
            ]);

            // ── Create lines so TaxCalculationService can group by tax_rate ──────
            /** @var array<string, numeric-string> $plannedBySourceLine */
            $plannedBySourceLine = [];
            foreach ($lineData as $idx => $ld) {
                /** @var DocumentLine|null $poLine */
                $poLine = $ld['sourceLineId'] !== null ? DocumentLine::find($ld['sourceLineId']) : null;
                $productId = $ld['productId'] ?? $poLine?->product_id;
                $variantId = $ld['variantId'] ?? $poLine?->variant_id;
                $product = $productId !== null ? Product::query()->find($productId) : null;
                $description = $poLine !== null ? $poLine->description : ($product instanceof Product ? $product->name : '');
                $snapshotAttributes = ['price_match_basis' => null, 'matched_receipt_line_id' => null];
                if ($ld['sourceLineId'] !== null && ! $ld['isBonusLine']) {
                    $alreadyPlanned = $plannedBySourceLine[$ld['sourceLineId']] ?? '0.0000';
                    $snapshotAttributes = $this->matchSnapshotAttributes($ld['sourceLineId'], $ld['qty'], $alreadyPlanned);
                    /** @var numeric-string $nextPlanned */
                    $nextPlanned = bcadd($alreadyPlanned, $ld['qty'], 4);
                    $plannedBySourceLine[$ld['sourceLineId']] = CurrencyScale::bcformatStrict($nextPlanned, 4);
                }

                DocumentLine::create([
                    'document_id' => $document->id,
                    'line_number' => $idx + 1,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'product_code' => $poLine->product_code ?? ($product instanceof Product ? $product->sku : null),
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
                    'is_bonus_line' => $ld['isBonusLine'],
                    ...$snapshotAttributes,
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
            $matchStatus = $pendingReceipt ? SupplierInvoiceMatchStatus::Unmatched : $this->matcher->match($document);
            $document->match_status = $matchStatus;
            $document->save();

            $document->load(['lines', 'partner', 'sourceDocument']);

            return $document;
        });
    }

    /**
     * @return array{price_match_basis: numeric-string|null, matched_receipt_line_id: string|null}
     */
    private function matchSnapshotAttributes(string $sourceLineId, string $qty, string $qtyAlreadyPlanned = '0.0000'): array
    {
        return $this->matchSnapshotService->forSourceLine($sourceLineId, $qty, $qtyAlreadyPlanned);
    }
}
