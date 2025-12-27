<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
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
        return DB::transaction(function () use ($sourceInvoiceId, $amount, $reason, $notes): Document {
            // 1. Lock the invoice row FIRST (pessimistic locking)
            /** @var Document|null $invoice */
            $invoice = Document::with('lines')
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
        return DB::transaction(function () use ($sourceInvoiceId, $lines, $reason, $notes): Document {
            // 1. Lock the invoice row FIRST (pessimistic locking)
            /** @var Document|null $invoice */
            $invoice = Document::with('lines')
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
}
