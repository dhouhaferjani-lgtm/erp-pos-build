<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Converter for Delivery Note(s) to Invoice conversion (Tunisia consolidation model).
 *
 * This converter supports consolidating multiple delivery notes into a single invoice,
 * which is the standard practice in Tunisia for B2B transactions.
 *
 * Options:
 * - 'delivery_note_ids' (array<int, string>): Array of delivery note IDs to consolidate.
 *   If provided, loads those documents and validates them. The source document is
 *   treated as the first delivery note if not in the array.
 *
 * Validates:
 * - All documents must be DeliveryNote type
 * - All delivery notes must be confirmed
 * - All delivery notes must belong to the same customer (partner_id)
 * - All delivery notes must belong to the same company (company_id)
 * - All delivery notes must have the same currency
 * - No delivery note has been invoiced already
 *
 * On conversion:
 * - Creates a new Invoice consolidating all delivery notes
 * - Sets due_date (30 days from invoice date)
 * - Reference field contains all DN document numbers
 * - Copies lines from all delivery notes with sequential numbering
 * - Links invoice lines to source DN lines via source_line_id
 * - Recalculates totals based on all lines
 * - Copies vehicle context from first delivery note
 * - Marks each DN as invoiced with timestamp and invoice_id
 * - Stores source_delivery_note_ids in invoice payload
 * - Dispatches DocumentConverted event for each DN (with consolidation metadata)
 */
final class DeliveryNoteToInvoiceConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::DeliveryNote;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::Invoice;
    }

    public function canConvert(Document $source): bool
    {
        return empty($this->getConversionErrors($source));
    }

    /**
     * @return array<int, string>
     */
    public function getConversionErrors(Document $source): array
    {
        $errors = [];

        if ($source->type !== DocumentType::DeliveryNote) {
            $errors[] = 'Source document must be a delivery note';
        }

        if ($source->status === DocumentStatus::Draft) {
            $errors[] = 'Delivery note must be confirmed before invoicing';
        }

        if ($source->status === DocumentStatus::Cancelled) {
            $errors[] = 'Cannot invoice cancelled delivery note';
        }

        // Check if already invoiced
        $payload = $source->payload ?? [];
        if (! empty($payload['invoiced_at'])) {
            $errors[] = 'Delivery note has already been invoiced';
        }

        // Check if has line items
        if ($source->lines->isEmpty()) {
            $errors[] = 'Delivery note must have at least one line item';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options  Options: 'delivery_note_ids' (array<int, string>)
     */
    public function convert(Document $source, array $options = []): Document
    {
        /** @var array<int, string>|null $deliveryNoteIds */
        $deliveryNoteIds = $options['delivery_note_ids'] ?? null;

        // If delivery_note_ids provided, load and consolidate multiple DNs
        if ($deliveryNoteIds !== null && count($deliveryNoteIds) > 0) {
            $deliveryNotes = $this->loadAndValidateDeliveryNotes($deliveryNoteIds);

            return $this->consolidateDeliveryNotes($deliveryNotes);
        }

        // Single DN conversion
        return $this->convertSingleDeliveryNote($source);
    }

    /**
     * Convert a single delivery note to an invoice.
     */
    private function convertSingleDeliveryNote(Document $dn): Document
    {
        $this->validateDeliveryNote($dn);

        return DB::transaction(function () use ($dn): Document {
            $invoice = $this->createTargetDocument($dn, DocumentType::Invoice, [
                'due_date' => now()->addDays(30),
            ]);

            // Copy lines with source linking
            $this->copyLinesWithSourceLink($dn, $invoice);

            // Recalculate totals
            $this->recalculateTotals($invoice);

            // Copy vehicle context if present
            $this->copyVehicleContext($dn, $invoice);

            // Store source DN ID in invoice payload
            $invoicePayload = $invoice->payload ?? [];
            $invoicePayload['source_delivery_note_ids'] = [$dn->id];
            $invoice->update(['payload' => $invoicePayload]);

            // Mark DN as invoiced
            $this->markDeliveryNoteAsInvoiced($dn, $invoice);

            // Dispatch conversion event
            $this->dispatchConversionEvent($dn, $invoice);

            /** @var Document */
            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Consolidate multiple delivery notes into a single invoice.
     *
     * @param  array<int, Document>  $deliveryNotes
     */
    private function consolidateDeliveryNotes(array $deliveryNotes): Document
    {
        if (empty($deliveryNotes)) {
            throw new \InvalidArgumentException('At least one delivery note is required');
        }

        $firstDn = $deliveryNotes[0];

        return DB::transaction(function () use ($deliveryNotes, $firstDn): Document {
            // Create reference string from all DN numbers
            $dnNumbers = array_map(fn (Document $dn) => $dn->document_number, $deliveryNotes);
            $reference = implode(', ', $dnNumbers);

            // Create the invoice
            $invoice = $this->createTargetDocument($firstDn, DocumentType::Invoice, [
                'due_date' => now()->addDays(30),
                'reference' => $reference,
            ]);

            // Copy lines from all DNs and track source DN IDs
            $sourceDeliveryNoteIds = [];
            $lineNumber = 0;

            foreach ($deliveryNotes as $dn) {
                $sourceDeliveryNoteIds[] = $dn->id;

                foreach ($dn->lines as $dnLine) {
                    $lineNumber++;

                    DocumentLine::create([
                        'id' => Str::uuid()->toString(),
                        'document_id' => $invoice->id,
                        'line_number' => $lineNumber,
                        'product_id' => $dnLine->product_id,
                        'product_code' => $dnLine->product_code,
                        'description' => $dnLine->description,
                        'quantity' => $dnLine->quantity,
                        'unit_price' => $dnLine->unit_price,
                        'discount_percent' => $dnLine->discount_percent,
                        'discount_amount' => $dnLine->discount_amount,
                        'tax_rate' => $dnLine->tax_rate,
                        'line_total' => $dnLine->line_total ?? '0.00',
                        'notes' => $dnLine->notes,
                        'designation_default_snapshot' => $dnLine->designation_default_snapshot,
                        'source_line_id' => $dnLine->id,
                    ]);
                }

                // Mark DN as invoiced
                $this->markDeliveryNoteAsInvoiced($dn, $invoice);
            }

            // Store source DN IDs in invoice payload
            $invoicePayload = $invoice->payload ?? [];
            $invoicePayload['source_delivery_note_ids'] = $sourceDeliveryNoteIds;
            $invoice->update(['payload' => $invoicePayload]);

            // Recalculate totals
            $this->recalculateTotals($invoice);

            // Copy vehicle context from first delivery note if present
            $this->copyVehicleContext($firstDn, $invoice);

            // Dispatch conversion events for each DN (consolidation audit trail)
            foreach ($deliveryNotes as $dn) {
                $this->dispatchConversionEvent($dn, $invoice, false, [
                    'consolidation' => true,
                    'total_dns_consolidated' => count($deliveryNotes),
                ]);
            }

            /** @var Document */
            return $invoice->fresh(['lines']);
        });
    }

    /**
     * Load and validate multiple delivery notes for consolidation.
     *
     * @param  array<int, string>  $deliveryNoteIds
     * @return array<int, Document>
     */
    private function loadAndValidateDeliveryNotes(array $deliveryNoteIds): array
    {
        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->where('type', DocumentType::DeliveryNote)
            ->get()
            ->all();

        if (empty($deliveryNotes)) {
            throw new \InvalidArgumentException('No valid delivery notes found');
        }

        // Validate all DNs
        $firstDn = $deliveryNotes[0];
        $partnerId = $firstDn->partner_id;
        $companyId = $firstDn->company_id;
        $currency = $firstDn->currency;

        foreach ($deliveryNotes as $dn) {
            $this->validateDeliveryNote($dn);

            // Same company (check before partner since partners are company-scoped)
            if ($dn->company_id !== $companyId) {
                throw new \DomainException('All delivery notes must belong to the same company');
            }

            // Same partner
            if ($dn->partner_id !== $partnerId) {
                throw new \DomainException('All delivery notes must belong to the same partner');
            }

            // Same currency
            if ($dn->currency !== $currency) {
                throw new \DomainException('All delivery notes must have the same currency');
            }
        }

        return $deliveryNotes;
    }

    /**
     * Validate a single delivery note for invoicing.
     */
    private function validateDeliveryNote(Document $dn): void
    {
        if ($dn->type !== DocumentType::DeliveryNote) {
            throw new \InvalidArgumentException('All documents must be delivery notes');
        }

        if ($dn->status === DocumentStatus::Draft) {
            throw new \DomainException('Delivery note must be confirmed before invoicing');
        }

        if ($dn->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot invoice cancelled delivery note');
        }

        // Check if already invoiced
        $payload = $dn->payload ?? [];
        if (! empty($payload['invoiced_at'])) {
            throw new \RuntimeException('Delivery note has already been invoiced');
        }
    }

    /**
     * Copy lines from a single DN to invoice with source linking.
     */
    private function copyLinesWithSourceLink(Document $dn, Document $invoice): void
    {
        foreach ($dn->lines as $line) {
            $this->copyLine($line, $invoice, null, null, null, true);
        }
    }

    /**
     * Mark a delivery note as invoiced.
     */
    private function markDeliveryNoteAsInvoiced(Document $dn, Document $invoice): void
    {
        $dnPayload = $dn->payload ?? [];
        $dnPayload['invoiced_at'] = now()->toDateTimeString();
        $dnPayload['invoice_id'] = $invoice->id;
        $dn->update(['payload' => $dnPayload]);
    }
}
