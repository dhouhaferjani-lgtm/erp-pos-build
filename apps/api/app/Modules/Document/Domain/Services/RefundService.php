<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Support\Facades\DB;

class RefundService
{
    /**
     * Cancel an invoice (only if not posted or not paid)
     */
    public function cancelInvoice(Document $invoice, string $reason): Document
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Document must be an invoice');
        }

        if ($invoice->status === DocumentStatus::Posted) {
            throw new \RuntimeException('Cannot cancel posted invoice. Create a credit note instead.');
        }

        if ($invoice->status === DocumentStatus::Paid) {
            throw new \RuntimeException('Cannot cancel paid invoice. Issue a refund instead.');
        }

        return DB::transaction(function () use ($invoice, $reason): Document {
            $invoice->update([
                'status' => DocumentStatus::Cancelled,
                'payload' => array_merge($invoice->payload ?? [], [
                    'cancelled_at' => now()->toDateTimeString(),
                    'cancellation_reason' => $reason,
                ]),
            ]);

            return $invoice;
        });
    }

    /**
     * Cancel a credit note (only if not posted)
     */
    public function cancelCreditNote(Document $creditNote, string $reason): Document
    {
        if ($creditNote->type !== DocumentType::CreditNote) {
            throw new \InvalidArgumentException('Document must be a credit note');
        }

        if ($creditNote->status === DocumentStatus::Posted) {
            throw new \RuntimeException('Cannot cancel posted credit note');
        }

        return DB::transaction(function () use ($creditNote, $reason): Document {
            $creditNote->update([
                'status' => DocumentStatus::Cancelled,
                'payload' => array_merge($creditNote->payload ?? [], [
                    'cancelled_at' => now()->toDateTimeString(),
                    'cancellation_reason' => $reason,
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Create a full credit note from a posted invoice
     */
    public function createFullCreditNote(
        Document $invoice,
        string $reason,
        DocumentNumberingService $numberingService
    ): Document {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Source document must be an invoice');
        }

        if ($invoice->status !== DocumentStatus::Posted && $invoice->status !== DocumentStatus::Paid) {
            throw new \RuntimeException('Can only create credit notes from posted or paid invoices');
        }

        // Check if already fully credited
        $payload = $invoice->payload ?? [];
        if (isset($payload['fully_credited']) && $payload['fully_credited'] === true) {
            throw new \RuntimeException('Invoice has already been fully credited');
        }

        return DB::transaction(function () use ($invoice, $reason, $numberingService): Document {
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'location_id' => $invoice->location_id,
                'partner_id' => $invoice->partner_id,
                'type' => DocumentType::CreditNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $numberingService->generateNumber(
                    tenantId: $invoice->tenant_id,
                    companyId: $invoice->company_id,
                    type: DocumentType::CreditNote
                ),
                'document_date' => now(),
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'notes' => "Credit note for invoice {$invoice->document_number}: {$reason}",
                'source_document_id' => $invoice->id,
                'payload' => [
                    'credit_reason' => $reason,
                    'credit_type' => 'full',
                ],
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

            // Copy all lines
            foreach ($invoice->lines as $line) {
                $creditNote->lines()->create([
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'line_total' => $line->line_total,
                    'line_number' => $line->line_number,
                ]);
            }

            // Mark invoice as credited
            $invoice->update([
                'payload' => array_merge($invoice->payload ?? [], [
                    'credit_note_ids' => array_merge(
                        $invoice->payload['credit_note_ids'] ?? [],
                        [$creditNote->id]
                    ),
                    'fully_credited' => true,
                    'credited_at' => now()->toDateTimeString(),
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Create a partial credit note from a posted invoice.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    public function createPartialCreditNote(
        Document $invoice,
        array $lineItems,
        string $reason,
        DocumentNumberingService $numberingService
    ): Document {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Source document must be an invoice');
        }

        if ($invoice->status !== DocumentStatus::Posted && $invoice->status !== DocumentStatus::Paid) {
            throw new \RuntimeException('Can only create credit notes from posted or paid invoices');
        }

        if (empty($lineItems)) {
            throw new \InvalidArgumentException('Line items are required for partial credit note');
        }

        return DB::transaction(function () use ($invoice, $lineItems, $reason, $numberingService): Document {
            $creditNote = Document::create([
                'tenant_id' => $invoice->tenant_id,
                'company_id' => $invoice->company_id,
                'location_id' => $invoice->location_id,
                'partner_id' => $invoice->partner_id,
                'type' => DocumentType::CreditNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $numberingService->generateNumber(
                    tenantId: $invoice->tenant_id,
                    companyId: $invoice->company_id,
                    type: DocumentType::CreditNote
                ),
                'document_date' => now(),
                'currency' => $invoice->currency,
                'notes' => "Partial credit note for invoice {$invoice->document_number}: {$reason}",
                'source_document_id' => $invoice->id,
                'payload' => [
                    'credit_reason' => $reason,
                    'credit_type' => 'partial',
                ],
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

            $subtotal = '0.00';
            $taxAmount = '0.00';
            $total = '0.00';

            // Create credit note lines from line items
            foreach ($lineItems as $item) {
                $creditNote->lines()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? '0.00',
                    'discount_amount' => $item['discount_amount'] ?? '0.00',
                    'tax_rate' => $item['tax_rate'] ?? '0.00',
                    'line_total' => $item['line_total'] ?? $item['total'] ?? '0.00',
                    'line_number' => $item['line_number'] ?? $item['sort_order'] ?? 0,
                ]);

                $lineTotal = $item['line_total'] ?? $item['total'] ?? '0.00';
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $taxAmount = bcadd($taxAmount, $item['tax_amount'] ?? '0.00', 2);
                $total = bcadd($total, $lineTotal, 2);
            }

            // Update credit note totals
            $creditNote->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            // Update invoice payload with credit note reference
            $invoice->update([
                'payload' => array_merge($invoice->payload ?? [], [
                    'credit_note_ids' => array_merge(
                        $invoice->payload['credit_note_ids'] ?? [],
                        [$creditNote->id]
                    ),
                    'partially_credited' => true,
                    'last_credit_at' => now()->toDateTimeString(),
                ]),
            ]);

            return $creditNote;
        });
    }

    /**
     * Check if invoice can be cancelled
     */
    public function canCancelInvoice(Document $invoice): bool
    {
        if ($invoice->type !== DocumentType::Invoice) {
            return false;
        }

        return ! in_array($invoice->status, [
            DocumentStatus::Posted,
            DocumentStatus::Paid,
            DocumentStatus::Cancelled,
        ], true);
    }

    /**
     * Check if invoice can be credited
     */
    public function canCreditInvoice(Document $invoice): bool
    {
        if ($invoice->type !== DocumentType::Invoice) {
            return false;
        }

        if (! in_array($invoice->status, [DocumentStatus::Posted, DocumentStatus::Paid], true)) {
            return false;
        }

        // Check if already fully credited
        $payload = $invoice->payload ?? [];

        return ! (isset($payload['fully_credited']) && $payload['fully_credited'] === true);
    }

    /**
     * Get credit note summary for an invoice.
     *
     * @return array<string, mixed>
     */
    public function getCreditNoteSummary(Document $invoice): array
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Document must be an invoice');
        }

        $payload = $invoice->payload ?? [];
        $creditNoteIds = $payload['credit_note_ids'] ?? [];

        if (empty($creditNoteIds)) {
            return [
                'has_credit_notes' => false,
                'credit_note_count' => 0,
                'total_credited_amount' => '0.00',
                'fully_credited' => false,
            ];
        }

        $creditNotes = Document::whereIn('id', $creditNoteIds)
            ->where('type', DocumentType::CreditNote)
            ->get();

        $totalCredited = '0.00';
        foreach ($creditNotes as $cn) {
            $totalCredited = bcadd($totalCredited, $cn->total ?? '0.00', 2);
        }

        return [
            'has_credit_notes' => true,
            'credit_note_count' => $creditNotes->count(),
            'total_credited_amount' => $totalCredited,
            'fully_credited' => $payload['fully_credited'] ?? false,
            'partially_credited' => $payload['partially_credited'] ?? false,
        ];
    }
}
