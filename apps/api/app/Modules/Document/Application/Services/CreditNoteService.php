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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

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
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($amount, $invoice->total, 4) > 0) {
                throw new \InvalidArgumentException('Credit note amount cannot exceed invoice total');
            }

            // Validation: Total credit notes cannot exceed invoice total
            /** @var numeric-string $totalCreditNotes */
            $totalCreditNotes = $invoice->creditNotes()->sum('total');
            /** @phpstan-ignore-next-line argument.type */
            $remainingAmount = bcsub($invoice->total, (string) $totalCreditNotes, 4);

            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($amount, $remainingAmount, 4) > 0) {
                throw new \InvalidArgumentException('Total credit notes would exceed invoice total');
            }

            $creditNoteNumber = $this->generateCreditNoteNumber($invoice->company_id);

            // Calculate proportional tax and subtotal
            /** @phpstan-ignore-next-line argument.type */
            $taxRate = bccomp($invoice->subtotal, '0', 4) > 0
                /** @phpstan-ignore-next-line argument.type */
                ? bcdiv($invoice->tax_amount ?? '0', $invoice->subtotal, 6)
                : '0';

            $subtotal = bcdiv($amount, bcadd('1', $taxRate, 6), 4);
            /** @phpstan-ignore-next-line argument.type */
            $taxAmount = bcsub($amount, $subtotal, 4);

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
            /** @var numeric-string $creditTotal */
            $creditTotal = $amount;
            $this->materializeProratedLines($creditNote, $invoice, $creditTotal, $subtotal);

            return $creditNote;
        });
    }

    /**
     * Build credit-note lines for an amount-based credit by prorating the source
     * invoice lines (preserving product, tax rate, and discount). When the source
     * has no lines, fall back to a single synthetic line carrying the net credit
     * at the invoice's blended tax rate so the document is never line-less.
     *
     * @param  numeric-string  $amount  requested credit total (tax-inclusive)
     * @param  numeric-string  $creditSubtotal  net portion of the credit
     */
    private function materializeProratedLines(
        Document $creditNote,
        Document $invoice,
        string $amount,
        string $creditSubtotal,
    ): void {
        /** @var Collection<int, DocumentLine> $sourceLines */
        $sourceLines = $invoice->lines;
        /** @var numeric-string $invoiceTotal */
        $invoiceTotal = (string) ($invoice->total ?? '0');

        if ($sourceLines->isEmpty() || bccomp($invoiceTotal, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity/amount comparison scale
            /** @var numeric-string $invoiceSubtotal */
            $invoiceSubtotal = (string) ($invoice->subtotal ?? '0');
            /** @var numeric-string $blendedRate */
            $blendedRate = bccomp($invoiceSubtotal, '0', 4) > 0 // precision-ok: 4 = canonical amount comparison scale
                // precision-ok: 6 = high-precision rate intermediate; 2 = tax_rate column scale
                ? bcmul(bcdiv((string) ($invoice->tax_amount ?? '0'), $invoiceSubtotal, 6), '100', 2)
                : '0';

            DocumentLine::create([
                'document_id' => $creditNote->id,
                'line_number' => 1,
                'description' => 'Credit',
                'quantity' => '1',
                'unit_price' => $creditSubtotal,
                'tax_rate' => $blendedRate,
                'line_total' => $creditSubtotal,
            ]);

            return;
        }

        /** @var numeric-string $ratio */
        $ratio = bcdiv($amount, $invoiceTotal, 6); // precision-ok: 6 = high-precision proration ratio intermediate
        $lineNumber = 1;

        foreach ($sourceLines as $sourceLine) {
            /** @var numeric-string $creditQty */
            $creditQty = bcmul((string) $sourceLine->quantity, $ratio, 4); // precision-ok: 4 = canonical quantity storage scale
            if (bccomp($creditQty, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity storage scale
                continue;
            }

            /** @var numeric-string|null $discountPercent */
            $discountPercent = $sourceLine->discount_percent !== null ? (string) $sourceLine->discount_percent : null;
            /** @var numeric-string|null $discountAmount */
            $discountAmount = $sourceLine->discount_amount !== null ? (string) $sourceLine->discount_amount : null;

            $lineTotal = DocumentLine::computeLineTotal(
                $creditQty,
                (string) $sourceLine->unit_price,
                $discountPercent,
                $discountAmount,
                4,
            );

            DocumentLine::create([
                'document_id' => $creditNote->id,
                'product_id' => $sourceLine->product_id,
                'line_number' => $lineNumber++,
                'description' => $sourceLine->description,
                'quantity' => $creditQty,
                'unit_price' => $sourceLine->unit_price,
                'discount_percent' => $sourceLine->discount_percent,
                'discount_amount' => $sourceLine->discount_amount,
                'tax_rate' => $sourceLine->tax_rate,
                'line_total' => $lineTotal,
                'designation_default_snapshot' => $sourceLine->designation_default_snapshot,
            ]);
        }
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

            $creditNoteNumber = $this->generateCreditNoteNumber($invoice->company_id);

            // Calculate totals from selected lines
            $subtotal = '0.00';
            $taxAmount = '0.00';

            /** @var array<string, DocumentLine> $lineMap */
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
                if (bccomp($quantity, '0', 4) <= 0 || bccomp($quantity, $invoiceLine->quantity, 4) > 0) {
                    throw new \InvalidArgumentException('Invalid quantity for line');
                }

                // Calculate line totals
                $lineSubtotal = bcmul($quantity, $invoiceLine->unit_price, 4);
                $taxRate = (string) ($invoiceLine->tax_rate ?? '0');
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 6), 4);

                $subtotal = bcadd($subtotal, $lineSubtotal, 4);
                $taxAmount = bcadd($taxAmount, $lineTax, 4);

                $lineMap[$lineId] = [
                    'invoiceLine' => $invoiceLine,
                    'quantity' => $quantity,
                ];
            }

            $total = bcadd($subtotal, $taxAmount, 4);

            // Validation: Total credit notes cannot exceed invoice total
            /** @var numeric-string $totalCreditNotes */
            $totalCreditNotes = $invoice->creditNotes()->sum('total');
            /** @phpstan-ignore-next-line argument.type */
            $remainingAmount = bcsub($invoice->total, (string) $totalCreditNotes, 4);

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

                $lineTotal = bcmul($quantity, $invoiceLine->unit_price, 4);

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
            $creditNoteNumber = $this->generateCreditNoteNumber($companyId);

            // Calculate totals from provided lines
            $subtotal = '0.0000';
            $taxAmount = '0.0000';

            foreach ($lines as $line) {
                $quantity = (string) $line['quantity'];
                $unitPrice = (string) $line['unit_price'];
                $taxRate = (string) $line['tax_rate'];

                // Validate quantity and price
                if (bccomp($quantity, '0', 4) <= 0) {
                    throw new \InvalidArgumentException('Line quantity must be greater than zero');
                }

                if (bccomp($unitPrice, '0', 4) < 0) {
                    throw new \InvalidArgumentException('Line unit price cannot be negative');
                }

                // Calculate line totals
                $lineSubtotal = bcmul($quantity, $unitPrice, 4);
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 6), 4);

                $subtotal = bcadd($subtotal, $lineSubtotal, 4);
                $taxAmount = bcadd($taxAmount, $lineTax, 4);
            }

            $total = bcadd($subtotal, $taxAmount, 4);

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
                'currency' => $partner->currency ?? 'TND',
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
                $lineTotal = bcmul($quantity, $unitPrice, 4);

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

            return $creditNote;
        });
    }

    /**
     * Generate sequential credit note number.
     */
    private function generateCreditNoteNumber(string $companyId): string
    {
        $lastCreditNote = Document::where('company_id', $companyId)
            ->where('type', DocumentType::CreditNote)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastCreditNote && preg_match('/CN-(\d+)/', $lastCreditNote->document_number, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('CN-%05d', $nextNumber);
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
