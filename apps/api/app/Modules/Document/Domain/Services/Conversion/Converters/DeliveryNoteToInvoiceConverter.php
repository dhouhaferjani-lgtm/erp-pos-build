<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteBatchValidationException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingClaimService;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingConcurrencyRetrier;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimRequest;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimSet;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

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
        private readonly DeliveryNoteBillingClaimService $billingClaimService,
        private readonly DeliveryNoteBillingConcurrencyRetrier $billingConcurrencyRetrier,
        private readonly CompanyContext $companyContext,
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
        $ids = $deliveryNoteIds !== null && count($deliveryNoteIds) > 0
            ? array_values($deliveryNoteIds)
            : [$source->id];
        $isConsolidation = $deliveryNoteIds !== null && count($deliveryNoteIds) > 0;

        try {
            return $this->billingConcurrencyRetrier->run($ids[0], function () use ($ids, $isConsolidation, $source): Document {
                $deliveryNotes = $this->loadAndValidateDeliveryNotes($ids, $source->id);

                return $isConsolidation
                    ? $this->consolidateDeliveryNotes($deliveryNotes)
                    : $this->convertSingleDeliveryNote($deliveryNotes[0]);
            });
        } catch (DeliveryNoteAlreadyClaimedException $exception) {
            $previous = $exception->getPrevious();
            if ($previous === null) {
                throw $exception;
            }
            if (! $this->isRetryExhaustion($previous)) {
                throw $exception;
            }

            $durableWinner = $this->durableDeliveryNoteWinner($source, $exception->deliveryNoteId);
            if ($durableWinner === null) {
                throw $previous;
            }

            throw new DeliveryNoteAlreadyClaimedException(
                $durableWinner['id'],
                $previous,
                $durableWinner['document_number'],
            );
        }
    }

    private function isRetryExhaustion(Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if (! $cursor instanceof PDOException) {
                continue;
            }

            $sqlState = $cursor->errorInfo[0] ?? $cursor->getCode();
            if (in_array((string) $sqlState, ['40P01', '40001'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{id: string, document_number: string}|null */
    private function durableDeliveryNoteWinner(Document $source, string $deliveryNoteId): ?array
    {
        $winner = DB::table('documents as delivery_note')
            ->join('delivery_note_billing_marks as mark', 'mark.delivery_note_id', '=', 'delivery_note.id')
            ->join('documents as invoice', 'invoice.id', '=', 'mark.invoice_id')
            ->where('delivery_note.tenant_id', $source->tenant_id)
            ->where('delivery_note.company_id', $source->company_id)
            ->where('delivery_note.type', DocumentType::DeliveryNote->value)
            ->where('delivery_note.id', $deliveryNoteId)
            ->whereColumn('mark.company_id', 'delivery_note.company_id')
            ->where('invoice.tenant_id', $source->tenant_id)
            ->where('invoice.company_id', $source->company_id)
            ->where('invoice.type', DocumentType::Invoice->value)
            ->select([
                'delivery_note.id',
                'delivery_note.document_number',
                'delivery_note.payload',
                'mark.invoice_id as winner_invoice_id',
                'mark.invoiced_via as winner_lane',
            ])
            ->first();

        if ($winner === null) {
            return null;
        }

        $payload = is_string($winner->payload)
            ? json_decode($winner->payload, true)
            : $winner->payload;
        if (! is_array($payload)
            || (string) ($payload['invoice_id'] ?? '') !== (string) $winner->winner_invoice_id
            || (string) ($payload['invoiced_via'] ?? '') !== (string) $winner->winner_lane
            || empty($payload['invoiced_at'])) {
            return null;
        }

        return [
            'id' => (string) $winner->id,
            'document_number' => (string) $winner->document_number,
        ];
    }

    /**
     * Convert a single delivery note to an invoice.
     */
    private function convertSingleDeliveryNote(Document $dn): Document
    {
        $invoice = null;

        $this->billingClaimService->claim(
            new DeliveryNoteClaimRequest(
                [$dn->id],
                $this->companyContext->requireCompanyId(),
                DeliveryNoteBillingLane::Consolidation,
            ),
            function (DeliveryNoteClaimSet $set) use ($dn, &$invoice): string {
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

                return $invoice->id;
            },
        );

        if (! $invoice instanceof Document) {
            throw new \LogicException('Delivery-note claim closure did not create an invoice.');
        }

        $this->dispatchConversionEvent($dn, $invoice);

        /** @var Document */
        return $invoice->fresh(['lines']);
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

        $invoice = null;

        $this->billingClaimService->claim(
            new DeliveryNoteClaimRequest(
                array_values(array_map(
                    static fn (Document $deliveryNote): string => $deliveryNote->id,
                    $deliveryNotes,
                )),
                $this->companyContext->requireCompanyId(),
                DeliveryNoteBillingLane::Consolidation,
            ),
            function (DeliveryNoteClaimSet $set) use ($deliveryNotes, $firstDn, &$invoice): string {
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
                }

                // Store source DN IDs in invoice payload
                $invoicePayload = $invoice->payload ?? [];
                $invoicePayload['source_delivery_note_ids'] = $sourceDeliveryNoteIds;
                $invoice->update(['payload' => $invoicePayload]);

                // Recalculate totals
                $this->recalculateTotals($invoice);

                // Copy vehicle context from first delivery note if present
                $this->copyVehicleContext($firstDn, $invoice);

                return $invoice->id;
            },
        );

        if (! $invoice instanceof Document) {
            throw new \LogicException('Delivery-note claim closure did not create an invoice.');
        }

        // Dispatch only after claim finalisation, while the outer transaction
        // is still open, so event-store and audit writes share its rollback.
        foreach ($deliveryNotes as $dn) {
            $this->dispatchConversionEvent($dn, $invoice, false, [
                'consolidation' => true,
                'total_dns_consolidated' => count($deliveryNotes),
            ]);
        }

        /** @var Document */
        return $invoice->fresh(['lines']);
    }

    /**
     * Load and validate multiple delivery notes for consolidation.
     *
     * @param  array<int, string>  $deliveryNoteIds
     * @return array<int, Document>
     */
    private function loadAndValidateDeliveryNotes(array $deliveryNoteIds, string $anchorId): array
    {
        $deliveryNoteIds = array_values(array_unique($deliveryNoteIds));
        sort($deliveryNoteIds, SORT_STRING);

        $deliveryNotes = Document::query()
            ->where('tenant_id', $this->companyContext->requireTenantId())
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->where('type', DocumentType::DeliveryNote->value)
            ->whereIn('id', $deliveryNoteIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->with('lines')
            ->get()
            ->all();

        if (count($deliveryNotes) !== count($deliveryNoteIds)) {
            throw new \InvalidArgumentException('One or more delivery notes were not found in the active company.');
        }

        $anchor = null;
        foreach ($deliveryNotes as $deliveryNote) {
            if ($deliveryNote->id === $anchorId) {
                $anchor = $deliveryNote;
                break;
            }
        }

        if (! $anchor instanceof Document) {
            throw new \InvalidArgumentException('The source delivery note was not found in the active company.');
        }

        $this->validateDeliveryNotes($deliveryNotes, $anchor);

        return $deliveryNotes;
    }

    /**
     * Collect every row-level failure before refusing the atomic batch.
     *
     * @param  array<int, Document>  $deliveryNotes
     */
    private function validateDeliveryNotes(array $deliveryNotes, Document $anchor): void
    {
        $failures = [];

        foreach ($deliveryNotes as $deliveryNote) {
            $reason = null;

            if ($deliveryNote->status === DocumentStatus::Cancelled) {
                $reason = 'cancelled';
            } elseif ($deliveryNote->status !== DocumentStatus::Confirmed) {
                $reason = 'not_confirmed';
            } elseif (! empty(($deliveryNote->payload ?? [])['invoiced_at'])) {
                $reason = 'already_invoiced';
            } elseif ($deliveryNote->partner_id !== $anchor->partner_id) {
                $reason = 'wrong_partner';
            } elseif ($deliveryNote->currency !== $anchor->currency) {
                $reason = 'wrong_currency';
            } elseif ($deliveryNote->lines->isEmpty()) {
                $reason = 'no_lines';
            }

            if ($reason !== null) {
                $failures[] = [
                    'id' => $deliveryNote->id,
                    'document_number' => $deliveryNote->document_number,
                    'reason' => $reason,
                ];
            }
        }

        if ($failures !== []) {
            throw new DeliveryNoteBatchValidationException($failures);
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
}
