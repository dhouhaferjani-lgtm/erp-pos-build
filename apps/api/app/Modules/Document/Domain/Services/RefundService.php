<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\CancelBlockReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Taxation\DocumentPeriodLockInterface;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DocumentPostingService $documentPostingService,
        private readonly DocumentPeriodLockInterface $periodLock,
    ) {}

    /**
     * Cancel an invoice (only if not posted or not paid)
     */
    public function cancelInvoice(Document $invoice, string $reason, ?string $actorId = null): Document
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new \InvalidArgumentException('Document must be an invoice');
        }

        if ($invoice->status === DocumentStatus::Posted) {
            return $this->documentPostingService->cancel($invoice, $reason, $actorId);
        }

        if ($invoice->status === DocumentStatus::Paid) {
            throw new \DomainException('DOCUMENT_HAS_PAYMENTS');
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
            return $this->documentPostingService->cancel($creditNote, $reason);
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

            // Resolve scale from the credited invoice's own currency so this
            // never depends on a bound CompanyContext (context-safe AND
            // fiscally correct: EUR→2, TND→3).
            $scale = $this->scaleResolver->getScale($invoice->currency);
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
                $subtotal = bcadd($subtotal, $lineTotal, $scale);
                $taxAmount = bcadd($taxAmount, $item['tax_amount'] ?? '0.00', $scale);
                $total = bcadd($total, $lineTotal, $scale);
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
        return $this->cancellationBlockReason($invoice) === null;
    }

    /**
     * WHY this invoice's Cancel action is unavailable, or NULL when it is.
     *
     * R2-F1 / GL gate I-3. This must agree with `DocumentPostingService::cancel()`
     * or the UI lies: before the period check was added here, an invoice sitting
     * in a FILED VAT period reported `can_cancel: true`, the front end rendered a
     * live Cancel button, and every click returned a 422. A FILED period can never
     * be reopened, so that button was a PERMANENT dead end — exactly the case the
     * read model has to pre-empt rather than discover on submit.
     *
     * Period refusals return the same codes the 422 carries, resolved through the
     * Shared contract so Document never touches Taxation internals.
     */
    public function cancellationBlockReason(Document $invoice): ?string
    {
        if ($invoice->type !== DocumentType::Invoice) {
            return CancelBlockReason::NotAnInvoice->value;
        }

        if ($invoice->status === DocumentStatus::Cancelled) {
            return CancelBlockReason::AlreadyCancelled->value;
        }

        if ($invoice->status === DocumentStatus::Posted) {
            if ($this->documentPostingService->hasBlockingAllocations($invoice)) {
                return CancelBlockReason::HasPayments->value;
            }

            // Only a POSTED invoice reaches DocumentPostingService::cancel() and
            // therefore the period guard; the draft/confirmed branch below never
            // touches the ledger.
            return $this->periodLock->cancellationRefusalCode($invoice);
        }

        if ($invoice->status === DocumentStatus::Paid) {
            return CancelBlockReason::HasPayments->value;
        }

        return null;
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

        $scale = $this->scaleResolver->getScale($invoice->currency);
        $totalCredited = '0.00';
        foreach ($creditNotes as $cn) {
            $totalCredited = bcadd($totalCredited, $cn->total ?? '0.00', $scale);
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
