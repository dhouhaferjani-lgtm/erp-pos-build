<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Concerns;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Shared functionality for document converters.
 *
 * Provides common methods for copying document data during conversions:
 * - Creating target documents with common fields
 * - Copying lines (full or partial)
 * - Copying vehicle context
 * - Linking documents
 * - Dispatching conversion events
 * - Recalculating totals
 */
trait CopiesDocumentData
{
    protected readonly DocumentNumberingService $numberingService;

    /**
     * Create a target document with common fields from the source document.
     *
     * @param  Document  $source  The source document
     * @param  DocumentType  $targetType  The type of document to create
     * @param  array<string, mixed>  $overrides  Additional fields to set or override
     * @return Document The newly created document
     */
    protected function createTargetDocument(
        Document $source,
        DocumentType $targetType,
        array $overrides = []
    ): Document {
        $defaults = [
            'tenant_id' => $source->tenant_id,
            'company_id' => $source->company_id,
            'location_id' => $source->location_id,
            'partner_id' => $source->partner_id,
            'type' => $targetType,
            'status' => DocumentStatus::Draft,
            'document_number' => $this->numberingService->generateNumber(
                $source->tenant_id,
                $source->company_id,
                $targetType
            ),
            'document_date' => now(),
            'currency' => $source->currency,
            'subtotal' => $source->subtotal,
            'discount_amount' => $source->discount_amount,
            'tax_amount' => $source->tax_amount,
            'total' => $source->total,
            'balance_due' => $source->total,
            'notes' => $source->notes,
            'internal_notes' => $source->internal_notes,
            'reference' => $source->document_number,
            'source_document_id' => $source->id,
        ];

        return Document::create(array_merge($defaults, $overrides));
    }

    /**
     * Copy all lines from source to destination document.
     *
     * @param  Document  $source  The source document
     * @param  Document  $destination  The destination document
     */
    protected function copyLines(Document $source, Document $destination): void
    {
        foreach ($source->lines as $line) {
            $this->copyLine($line, $destination);
        }
    }

    /**
     * Copy a single line to a destination document.
     *
     * @param  DocumentLine  $line  The source line
     * @param  Document  $destination  The destination document
     * @param  int|null  $lineNumber  Optional line number override
     * @param  string|null  $quantity  Optional quantity override
     * @param  string|null  $lineTotal  Optional line total override
     * @param  bool  $linkSource  Whether to link to the source line via source_line_id
     * @return DocumentLine The created line
     */
    protected function copyLine(
        DocumentLine $line,
        Document $destination,
        ?int $lineNumber = null,
        ?string $quantity = null,
        ?string $lineTotal = null,
        bool $linkSource = false
    ): DocumentLine {
        return DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $destination->id,
            'line_number' => $lineNumber ?? $line->line_number,
            'product_id' => $line->product_id,
            'product_code' => $line->product_code,
            'service_id' => $line->service_id,
            'description' => $line->description,
            'quantity' => $quantity ?? $line->quantity,
            'unit_price' => $line->unit_price,
            'discount_percent' => $line->discount_percent,
            'discount_amount' => $line->discount_amount,
            'tax_rate' => $line->tax_rate,
            'line_total' => $lineTotal ?? $line->line_total ?? '0.00',
            'notes' => $line->notes,
            'source_line_id' => $linkSource ? $line->id : null,
        ]);
    }

    /**
     * Copy selected lines from source to destination document.
     *
     * @param  Document  $source  The source document
     * @param  Document  $destination  The destination document
     * @param  array<int, string>  $lineIds  Array of line IDs to copy
     */
    protected function copyPartialLines(Document $source, Document $destination, array $lineIds): void
    {
        $lines = $source->lines()->whereIn('id', $lineIds)->get();

        foreach ($lines as $line) {
            $this->copyLine($line, $destination);
        }
    }

    /**
     * Copy vehicle context from source document to target document.
     *
     * If the source document has a vehicle context, this method creates
     * a new vehicle context for the target document with the same vehicle
     * and context data.
     *
     * @param  Document  $source  The source document
     * @param  Document  $target  The target document
     */
    protected function copyVehicleContext(Document $source, Document $target): void
    {
        $sourceContext = $source->vehicleContext;

        if ($sourceContext !== null) {
            DocumentVehicleContext::create([
                'document_id' => $target->id,
                'vehicle_id' => $sourceContext->vehicle_id,
                'vehicle_snapshot' => $sourceContext->vehicle_snapshot,
                'mileage_at_service' => $sourceContext->mileage_at_service,
                'context_data' => $sourceContext->context_data,
            ]);
        }
    }

    /**
     * Link source and target documents by updating the source payload.
     *
     * @param  Document  $source  The source document
     * @param  Document  $target  The target document
     * @param  string  $payloadKey  The key to use in the source payload (e.g., 'converted_to_order_id')
     */
    protected function linkDocuments(Document $source, Document $target, string $payloadKey): void
    {
        $source->update([
            'payload' => array_merge($source->payload ?? [], [
                $payloadKey => $target->id,
                'converted_at' => now()->toDateTimeString(),
            ]),
        ]);
    }

    /**
     * Add target document ID to an array in the source payload.
     *
     * Used for one-to-many relationships like order -> multiple invoices.
     *
     * @param  Document  $source  The source document
     * @param  Document  $target  The target document
     * @param  string  $payloadKey  The array key in the source payload (e.g., 'invoice_ids')
     * @param  array<string, mixed>  $additionalPayload  Additional payload fields to set
     */
    protected function appendToSourcePayload(
        Document $source,
        Document $target,
        string $payloadKey,
        array $additionalPayload = []
    ): void {
        $sourcePayload = $source->payload ?? [];
        $sourcePayload[$payloadKey] = array_merge(
            $sourcePayload[$payloadKey] ?? [],
            [$target->id]
        );

        $source->update(['payload' => array_merge($sourcePayload, $additionalPayload)]);
    }

    /**
     * Dispatch a DocumentConverted event for audit trail.
     *
     * @param  Document  $source  The source document being converted
     * @param  Document  $target  The newly created document
     * @param  bool  $isPartial  Whether this was a partial conversion
     * @param  array<string, mixed>  $metadata  Additional metadata about the conversion
     */
    protected function dispatchConversionEvent(
        Document $source,
        Document $target,
        bool $isPartial = false,
        array $metadata = []
    ): void {
        $authId = Auth::id();
        $userId = $authId !== null ? (string) $authId : null;

        event(new DocumentConverted(
            sourceDocumentId: $source->id,
            targetDocumentId: $target->id,
            companyId: $source->company_id,
            tenantId: $source->tenant_id,
            sourceDocumentNumber: $source->document_number,
            targetDocumentNumber: $target->document_number,
            sourceType: $source->type->value,
            targetType: $target->type->value,
            userId: $userId,
            convertedAt: now()->toIso8601String(),
            isPartial: $isPartial,
            metadata: $metadata,
        ));
    }

    /**
     * Recalculate document totals based on lines.
     *
     * @param  Document  $document  The document to recalculate
     */
    protected function recalculateTotals(Document $document): void
    {
        $subtotal = '0.00';
        $taxAmount = '0.00';
        $total = '0.00';

        foreach ($document->lines as $line) {
            // DocumentLine has line_total which represents the line subtotal before tax
            $lineTotal = (string) $line->line_total;
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            // Calculate tax for this line if tax_rate is set
            if ($line->tax_rate !== null && $line->tax_rate !== '0.00') {
                $lineTax = bcmul($lineTotal, bcdiv((string) $line->tax_rate, '100', 4), 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);
            }
        }

        $total = bcadd($subtotal, $taxAmount, 2);

        $document->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
        ]);
    }
}
