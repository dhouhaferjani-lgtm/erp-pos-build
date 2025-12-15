<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentConversionService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly GeneralLedgerService $glService
    ) {}

    /**
     * Convert a quote to a sales order
     */
    public function convertQuoteToOrder(Document $quote): Document
    {
        if ($quote->type !== DocumentType::Quote) {
            throw new \InvalidArgumentException('Source document must be a quote');
        }

        if ($quote->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled quote');
        }

        if ($quote->status === DocumentStatus::Draft) {
            throw new \DomainException('Quote must be confirmed before conversion', 422);
        }

        // Check if quote is expired
        if ($quote->valid_until && $quote->valid_until->isPast()) {
            throw new \RuntimeException('Cannot convert expired quote');
        }

        return DB::transaction(function () use ($quote): Document {
            // Create sales order
            $order = Document::create([
                'tenant_id' => $quote->tenant_id,
                'company_id' => $quote->company_id,
                'location_id' => $quote->location_id,
                'partner_id' => $quote->partner_id,
                'vehicle_id' => $quote->vehicle_id,
                'type' => DocumentType::SalesOrder,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $quote->tenant_id,
                    $quote->company_id,
                    DocumentType::SalesOrder
                ),
                'document_date' => now(),
                'currency' => $quote->currency,
                'subtotal' => $quote->subtotal,
                'discount_amount' => $quote->discount_amount,
                'tax_amount' => $quote->tax_amount,
                'total' => $quote->total,
                'balance_due' => $quote->total,
                'notes' => $quote->notes,
                'internal_notes' => $quote->internal_notes,
                'reference' => $quote->document_number,
                'source_document_id' => $quote->id,
            ]);

            // Copy lines
            $this->copyLines($quote, $order);

            // Mark quote as converted
            $quote->update([
                'payload' => array_merge($quote->payload ?? [], [
                    'converted_to_order_id' => $order->id,
                    'converted_at' => now()->toDateTimeString(),
                ]),
            ]);

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($quote, $order);

            return $order;
        });
    }

    /**
     * Convert a sales order to an invoice
     *
     * Tunisia fiscal compliance requires:
     * - Services only: Can be invoiced directly from SO
     * - Products only: Must have delivery notes before invoicing
     * - Mixed: Physical items must be delivered before invoicing
     *
     * @param  array<int, string>|null  $lineIds
     */
    public function convertOrderToInvoice(Document $order, bool $partial = false, ?array $lineIds = null): Document
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Source document must be a sales order');
        }

        if ($order->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled sales order');
        }

        if ($order->status === DocumentStatus::Draft) {
            throw new \DomainException('Sales order must be confirmed before conversion', 422);
        }

        // Prevent duplicate invoicing - check if order is already fully invoiced
        if (! $partial && $this->isOrderFullyInvoiced($order)) {
            throw new \RuntimeException('Sales order has already been fully invoiced');
        }

        // Tunisia fiscal compliance: check if physical items require delivery notes
        $scenario = $this->detectOrderScenario($order, $lineIds);

        if ($scenario === 'products_only') {
            // Physical products require delivery notes before invoicing
            if (! $this->hasDeliveryNotesForPhysicalItems($order)) {
                throw new \DomainException(
                    'Physical products must be delivered before invoicing. Create a delivery note first.',
                    422
                );
            }
        } elseif ($scenario === 'mixed') {
            // Mixed order: physical items must be delivered
            if (! $this->hasDeliveryNotesForPhysicalItems($order)) {
                throw new \DomainException(
                    'Physical products in this order must be delivered before invoicing. '.
                    'Create a delivery note for physical items first, then invoice.',
                    422
                );
            }
        }
        // scenario === 'services_only': allow direct invoicing

        return DB::transaction(function () use ($order, $partial, $lineIds): Document {
            $invoice = Document::create([
                'tenant_id' => $order->tenant_id,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'partner_id' => $order->partner_id,
                'vehicle_id' => $order->vehicle_id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $order->tenant_id,
                    $order->company_id,
                    DocumentType::Invoice
                ),
                'document_date' => now(),
                'due_date' => now()->addDays(30),
                'currency' => $order->currency,
                'notes' => $order->notes,
                'internal_notes' => $order->internal_notes,
                'reference' => $order->document_number,
                'source_document_id' => $order->id,
            ]);

            // Copy lines (all or partial)
            if ($partial && $lineIds !== null) {
                $this->copyPartialLines($order, $invoice, $lineIds);
            } else {
                $this->copyLines($order, $invoice);
            }

            // Recalculate totals
            $this->recalculateTotals($invoice);

            // Transfer any prepayments from the order to the invoice
            $this->transferPrepayments($order, $invoice);

            // Update order payload
            $orderPayload = $order->payload ?? [];
            $orderPayload['invoice_ids'] = array_merge(
                $orderPayload['invoice_ids'] ?? [],
                [$invoice->id]
            );

            if (! $partial) {
                $orderPayload['fully_invoiced'] = true;
                $orderPayload['fully_invoiced_at'] = now()->toDateTimeString();
            }

            $order->update(['payload' => $orderPayload]);

            // Mark associated delivery notes as invoiced
            // This prevents them from showing in DN consolidation page
            $this->markDeliveryNotesAsInvoiced($order, $invoice);

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($order, $invoice, $partial);

            return $invoice;
        });
    }

    /**
     * Convert a sales order to a delivery note (full delivery).
     *
     * Creates a delivery note with all remaining quantities from the order.
     * Updates quantity_delivered on source order lines.
     */
    public function convertOrderToDelivery(Document $order): Document
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Source document must be a sales order');
        }

        if ($order->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled sales order');
        }

        if ($order->status === DocumentStatus::Draft) {
            throw new \DomainException('Sales order must be confirmed before conversion to delivery note', 422);
        }

        // Check if already fully delivered using line-level tracking
        if ($order->getDeliveryStatus() === DeliveryStatus::FullyDelivered) {
            throw new \RuntimeException('Sales order has already been fully delivered');
        }

        return DB::transaction(function () use ($order): Document {
            $delivery = Document::create([
                'tenant_id' => $order->tenant_id,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'partner_id' => $order->partner_id,
                'vehicle_id' => $order->vehicle_id,
                'type' => DocumentType::DeliveryNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $order->tenant_id,
                    $order->company_id,
                    DocumentType::DeliveryNote
                ),
                'document_date' => now(),
                'currency' => $order->currency,
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'tax_amount' => $order->tax_amount,
                'total' => $order->total,
                'notes' => $order->notes,
                'internal_notes' => $order->internal_notes,
                'reference' => $order->document_number,
                'source_document_id' => $order->id,
            ]);

            // Copy lines and update quantity_delivered on source lines
            $this->copyLinesForFullDelivery($order, $delivery);

            // Update order payload to track delivery notes (as array, like invoice_ids)
            $orderPayload = $order->payload ?? [];
            $orderPayload['delivery_note_ids'] = array_merge(
                $orderPayload['delivery_note_ids'] ?? [],
                [$delivery->id]
            );

            // For non-partial delivery (full order), mark as fully delivered
            $orderPayload['fully_delivered'] = true;
            $orderPayload['fully_delivered_at'] = now()->toDateTimeString();

            $order->update(['payload' => $orderPayload]);

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($order, $delivery);

            return $delivery;
        });
    }

    /**
     * Convert a sales order to a partial delivery note.
     *
     * Creates a delivery note with specified quantities per line.
     * Updates quantity_delivered on source order lines.
     * Links delivery note lines to source order lines via source_line_id.
     *
     * @param  Document  $order  The sales order to create a partial delivery from
     * @param  array<string, string>  $deliveryQuantities  Map of order line IDs to quantities to deliver
     * @return Document The created delivery note
     *
     * @throws \InvalidArgumentException If source is not a sales order
     * @throws \RuntimeException If order is cancelled or fully delivered
     * @throws \DomainException If order is draft, quantities exceed remaining, or all quantities are zero
     */
    public function convertOrderToPartialDelivery(Document $order, array $deliveryQuantities): Document
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Source document must be a sales order');
        }

        if ($order->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled sales order');
        }

        if ($order->status === DocumentStatus::Draft) {
            throw new \DomainException('Sales order must be confirmed before conversion to delivery note', 422);
        }

        // Check if already fully delivered using line-level tracking
        if ($order->getDeliveryStatus() === DeliveryStatus::FullyDelivered) {
            throw new \RuntimeException('Sales order has already been fully delivered');
        }

        // Validate that at least one line has a positive quantity
        $hasPositiveQuantity = false;
        foreach ($deliveryQuantities as $quantity) {
            /** @var numeric-string $qty */
            $qty = (string) $quantity;
            if (bccomp($qty, '0.00', 4) > 0) {
                $hasPositiveQuantity = true;
                break;
            }
        }

        if (! $hasPositiveQuantity) {
            throw new \DomainException('At least one line must have a quantity greater than zero');
        }

        // Validate quantities against remaining
        foreach ($order->lines as $line) {
            /** @var numeric-string $requestedQty */
            $requestedQty = (string) ($deliveryQuantities[$line->id] ?? '0.00');
            if (bccomp($requestedQty, '0.00', 4) > 0) {
                /** @var numeric-string $remaining */
                $remaining = (string) $line->getQuantityRemaining();
                if (bccomp($requestedQty, $remaining, 4) > 0) {
                    throw new \DomainException('Cannot deliver more than remaining quantity');
                }
            }
        }

        return DB::transaction(function () use ($order, $deliveryQuantities): Document {
            $delivery = Document::create([
                'tenant_id' => $order->tenant_id,
                'company_id' => $order->company_id,
                'location_id' => $order->location_id,
                'partner_id' => $order->partner_id,
                'vehicle_id' => $order->vehicle_id,
                'type' => DocumentType::DeliveryNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $order->tenant_id,
                    $order->company_id,
                    DocumentType::DeliveryNote
                ),
                'document_date' => now(),
                'currency' => $order->currency,
                'notes' => $order->notes,
                'internal_notes' => $order->internal_notes,
                'reference' => $order->document_number,
                'source_document_id' => $order->id,
            ]);

            // Copy lines with specified quantities and link to source
            $this->copyLinesForPartialDelivery($order, $delivery, $deliveryQuantities);

            // Recalculate totals based on partial quantities
            $this->recalculateTotals($delivery);

            // Update order payload to track delivery notes
            $orderPayload = $order->payload ?? [];
            $orderPayload['delivery_note_ids'] = array_merge(
                $orderPayload['delivery_note_ids'] ?? [],
                [$delivery->id]
            );

            $order->update(['payload' => $orderPayload]);

            // Dispatch conversion event for audit trail (mark as partial)
            $this->dispatchConversionEvent($order, $delivery, true);

            return $delivery;
        });
    }

    /**
     * Check if order has been fully delivered
     */
    public function isOrderFullyDelivered(Document $order): bool
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Document must be a sales order');
        }

        $payload = $order->payload ?? [];

        return $payload['fully_delivered'] ?? false;
    }

    /**
     * Copy all lines from source to destination document
     */
    private function copyLines(Document $source, Document $destination): void
    {
        foreach ($source->lines as $line) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $destination->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'line_total' => $line->line_total ?? '0.00',
                'notes' => $line->notes,
            ]);
        }
    }

    /**
     * Copy selected lines from source to destination document
     *
     * @param  array<int, string>  $lineIds
     */
    private function copyPartialLines(Document $source, Document $destination, array $lineIds): void
    {
        $lines = $source->lines()->whereIn('id', $lineIds)->get();

        foreach ($lines as $line) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $destination->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'line_total' => $line->line_total ?? '0.00',
                'notes' => $line->notes,
            ]);
        }
    }

    /**
     * Copy all lines from source order to delivery note and update quantity_delivered.
     *
     * This is used for full delivery - all quantities are delivered.
     * Each source line's quantity_delivered is set to the full quantity.
     */
    private function copyLinesForFullDelivery(Document $source, Document $destination): void
    {
        foreach ($source->lines as $line) {
            // Create delivery note line linked to source
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $destination->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'line_total' => $line->line_total ?? '0.00',
                'notes' => $line->notes,
                'source_line_id' => $line->id,
            ]);

            // Update source line's quantity_delivered to full quantity
            $line->update([
                'quantity_delivered' => $line->quantity,
            ]);
        }
    }

    /**
     * Copy lines with partial quantities from source order to delivery note.
     *
     * Only creates lines for quantities > 0.
     * Updates quantity_delivered on source lines by the delivered amount.
     * Links delivery note lines to source order lines via source_line_id.
     *
     * @param  array<string, string>  $deliveryQuantities  Map of order line IDs to quantities to deliver
     */
    private function copyLinesForPartialDelivery(Document $source, Document $destination, array $deliveryQuantities): void
    {
        $lineNumber = 0;

        foreach ($source->lines as $line) {
            /** @var numeric-string $qtyToDeliver */
            $qtyToDeliver = (string) ($deliveryQuantities[$line->id] ?? '0.00');

            // Skip lines with zero quantity
            if (bccomp($qtyToDeliver, '0.00', 4) <= 0) {
                continue;
            }

            $lineNumber++;

            // Calculate line total based on partial quantity
            /** @var numeric-string $unitPrice */
            $unitPrice = (string) $line->unit_price;
            $lineTotal = bcmul($qtyToDeliver, $unitPrice, 2);

            // Apply discount if any
            if ($line->discount_percent !== null && $line->discount_percent !== '0.00') {
                /** @var numeric-string $discountPercent */
                $discountPercent = (string) $line->discount_percent;
                $discount = bcmul($lineTotal, bcdiv($discountPercent, '100', 4), 2);
                $lineTotal = bcsub($lineTotal, $discount, 2);
            } elseif ($line->discount_amount !== null && $line->discount_amount !== '0.00') {
                // For fixed discount, prorate based on quantity ratio
                /** @var numeric-string $lineQty */
                $lineQty = (string) $line->quantity;
                /** @var numeric-string $discountAmt */
                $discountAmt = (string) $line->discount_amount;
                $qtyRatio = bcdiv($qtyToDeliver, $lineQty, 4);
                $proratedDiscount = bcmul($discountAmt, $qtyRatio, 2);
                $lineTotal = bcsub($lineTotal, $proratedDiscount, 2);
            }

            // Create delivery note line linked to source
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $destination->id,
                'line_number' => $lineNumber,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $qtyToDeliver,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'line_total' => $lineTotal,
                'notes' => $line->notes,
                'source_line_id' => $line->id,
            ]);

            // Update source line's quantity_delivered
            /** @var numeric-string $currentDelivered */
            $currentDelivered = (string) ($line->quantity_delivered ?? '0.00');
            $newDelivered = bcadd($currentDelivered, $qtyToDeliver, 4);
            $line->update([
                'quantity_delivered' => $newDelivered,
            ]);
        }
    }

    /**
     * Recalculate document totals based on lines
     */
    private function recalculateTotals(Document $document): void
    {
        $subtotal = '0.00';
        $taxAmount = '0.00';
        $total = '0.00';

        foreach ($document->lines as $line) {
            // DocumentLine has line_total which represents the line subtotal before tax
            // Cast to string in case it's stored as a number
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

    /**
     * Check if quote has expired
     */
    public function isQuoteExpired(Document $quote): bool
    {
        if ($quote->type !== DocumentType::Quote) {
            throw new \InvalidArgumentException('Document must be a quote');
        }

        return $quote->valid_until !== null && $quote->valid_until->isPast();
    }

    /**
     * Check if order has been fully invoiced
     */
    public function isOrderFullyInvoiced(Document $order): bool
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Document must be a sales order');
        }

        $payload = $order->payload ?? [];

        return $payload['fully_invoiced'] ?? false;
    }

    /**
     * Create an invoice from one or more delivery notes (Tunisia consolidation model).
     *
     * Consolidates multiple confirmed delivery notes into a single invoice.
     * All DNs must belong to the same partner, company, and have the same currency.
     * Marks each DN as invoiced with timestamp and invoice reference.
     *
     * @param  array<int, Document>  $deliveryNotes  Array of confirmed delivery notes to invoice
     * @return Document The created invoice
     *
     * @throws \InvalidArgumentException If no delivery notes provided
     * @throws \DomainException If DNs have different partners, companies, or currencies
     * @throws \RuntimeException If any DN is already invoiced
     */
    public function createInvoiceFromDeliveryNotes(array $deliveryNotes): Document
    {
        if (empty($deliveryNotes)) {
            throw new \InvalidArgumentException('At least one delivery note is required');
        }

        // Validate all DNs
        $firstDn = $deliveryNotes[0];
        $partnerId = $firstDn->partner_id;
        $companyId = $firstDn->company_id;
        $tenantId = $firstDn->tenant_id;
        $currency = $firstDn->currency;

        foreach ($deliveryNotes as $dn) {
            // Must be a delivery note
            if ($dn->type !== DocumentType::DeliveryNote) {
                throw new \InvalidArgumentException('All documents must be delivery notes');
            }

            // Must be confirmed
            if ($dn->status === DocumentStatus::Draft) {
                throw new \DomainException('Delivery note must be confirmed before invoicing');
            }

            // Must not be already invoiced
            $payload = $dn->payload ?? [];
            if (! empty($payload['invoiced_at'])) {
                throw new \RuntimeException('Delivery note has already been invoiced');
            }

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

        return DB::transaction(function () use ($deliveryNotes, $firstDn, $tenantId, $companyId, $partnerId, $currency): Document {
            // Create reference string from all DN numbers
            $dnNumbers = array_map(fn (Document $dn) => $dn->document_number, $deliveryNotes);
            $reference = implode(', ', $dnNumbers);

            // Create the invoice
            $invoice = Document::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'location_id' => $firstDn->location_id,
                'partner_id' => $partnerId,
                'vehicle_id' => $firstDn->vehicle_id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Draft,
                'document_number' => $this->numberingService->generateNumber(
                    $tenantId,
                    $companyId,
                    DocumentType::Invoice
                ),
                'document_date' => now(),
                'due_date' => now()->addDays(30),
                'currency' => $currency,
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
                        'description' => $dnLine->description,
                        'quantity' => $dnLine->quantity,
                        'unit_price' => $dnLine->unit_price,
                        'discount_percent' => $dnLine->discount_percent,
                        'discount_amount' => $dnLine->discount_amount,
                        'tax_rate' => $dnLine->tax_rate,
                        'line_total' => $dnLine->line_total ?? '0.00',
                        'notes' => $dnLine->notes,
                        'source_line_id' => $dnLine->id,
                    ]);
                }

                // Mark DN as invoiced
                $dnPayload = $dn->payload ?? [];
                $dnPayload['invoiced_at'] = now()->toDateTimeString();
                $dnPayload['invoice_id'] = $invoice->id;
                $dn->update(['payload' => $dnPayload]);
            }

            // Store source DN IDs in invoice payload
            $invoicePayload = $invoice->payload ?? [];
            $invoicePayload['source_delivery_note_ids'] = $sourceDeliveryNoteIds;
            $invoice->update(['payload' => $invoicePayload]);

            // Recalculate totals
            $this->recalculateTotals($invoice);

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
     * Transfer prepayments from a sales order to the resulting invoice.
     *
     * When a sales order with prepayments (acomptes) is converted to an invoice,
     * this method:
     * 1. Moves payment allocations from the order to the invoice
     * 2. Updates the invoice's balance_due to reflect prepaid amounts
     * 3. Creates GL entry to clear the customer advance against receivables
     * 4. Stores metadata for audit trail
     */
    private function transferPrepayments(Document $order, Document $invoice): void
    {
        // Get all payment allocations from the sales order
        $allocations = PaymentAllocation::where('document_id', $order->id)->get();

        if ($allocations->isEmpty()) {
            return; // No prepayments to transfer
        }

        // Calculate total prepaid amount
        $totalPrepaid = '0.00';
        foreach ($allocations as $allocation) {
            $totalPrepaid = bcadd($totalPrepaid, (string) $allocation->amount, 2);
        }

        // Transfer each allocation to the invoice
        foreach ($allocations as $allocation) {
            $allocation->update([
                'document_id' => $invoice->id,
            ]);
        }

        // Update invoice balance_due to reflect prepayments
        $invoiceTotal = (string) $invoice->total;
        if ($invoiceTotal === '') {
            $invoiceTotal = '0.00';
        }
        $newBalanceDue = bcsub($invoiceTotal, $totalPrepaid, 2);
        if (bccomp($newBalanceDue, '0.00', 2) < 0) {
            $newBalanceDue = '0.00'; // Cannot be negative
        }

        $invoice->update([
            'balance_due' => $newBalanceDue,
        ]);

        // Store prepayment transfer metadata in invoice payload for audit trail
        $invoicePayload = $invoice->payload ?? [];
        $invoicePayload['prepayments_transferred'] = [
            'from_order_id' => $order->id,
            'from_order_number' => $order->document_number,
            'total_amount' => $totalPrepaid,
            'allocation_count' => $allocations->count(),
            'transferred_at' => now()->toDateTimeString(),
        ];
        $invoice->update(['payload' => $invoicePayload]);

        // Create GL entry to clear the advance when the invoice is posted
        // Note: The GL entry is created here but only posted when the invoice is posted
        // This ensures proper accounting: Dr. Customer Advances (4191), Cr. AR (411)
        if (bccomp($totalPrepaid, '0.00', 2) > 0) {
            try {
                $this->glService->clearCustomerAdvanceToReceivable(
                    $invoice->company_id,
                    $invoice->partner_id,
                    $invoice->id,
                    $totalPrepaid,
                    now(),
                    "Prepayment applied from order {$order->document_number} to invoice {$invoice->document_number}"
                );
            } catch (\RuntimeException $e) {
                // If accounts are not configured, log warning but don't fail the conversion
                // The payment allocation transfer is still valuable for treasury tracking
                \Illuminate\Support\Facades\Log::warning(
                    'Could not create GL entry for prepayment transfer: '.$e->getMessage(),
                    [
                        'order_id' => $order->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $totalPrepaid,
                    ]
                );

                // Record that GL entry was skipped in the payload
                $invoicePayload = $invoice->payload ?? [];
                $invoicePayload['prepayments_transferred']['gl_entry_skipped'] = true;
                $invoicePayload['prepayments_transferred']['gl_skip_reason'] = $e->getMessage();
                $invoice->update(['payload' => $invoicePayload]);
            }
        }
    }

    /**
     * Detect the order scenario based on product types in the lines.
     *
     * Returns:
     * - 'services_only': All lines are non-physical (services)
     * - 'products_only': All lines are physical products
     * - 'mixed': Order contains both services and physical products
     *
     * @param  array<int, string>|null  $lineIds  If partial, only check these lines
     * @return 'services_only'|'products_only'|'mixed'
     */
    private function detectOrderScenario(Document $order, ?array $lineIds = null): string
    {
        $lines = $lineIds !== null
            ? $order->lines()->whereIn('id', $lineIds)->get()
            : $order->lines;

        $hasPhysical = false;
        $hasNonPhysical = false;

        foreach ($lines as $line) {
            // If no product_id, treat as service (e.g., manual line items)
            if ($line->product_id === null) {
                $hasNonPhysical = true;

                continue;
            }

            $product = Product::find($line->product_id);
            if ($product === null) {
                // Product not found, treat as service for safety
                $hasNonPhysical = true;

                continue;
            }

            if ($product->isPhysical()) {
                $hasPhysical = true;
            } else {
                $hasNonPhysical = true;
            }
        }

        if ($hasPhysical && $hasNonPhysical) {
            return 'mixed';
        }

        if ($hasPhysical) {
            return 'products_only';
        }

        return 'services_only';
    }

    /**
     * Check if an order has delivery notes for its physical items.
     *
     * For Tunisia compliance, physical products must be delivered before invoicing.
     * This checks if the order's physical line items have been included in delivery notes.
     *
     * @return bool True if all physical items have associated delivery notes
     */
    private function hasDeliveryNotesForPhysicalItems(Document $order): bool
    {
        // Get delivery note IDs from payload
        $payload = $order->payload ?? [];
        $deliveryNoteIds = $payload['delivery_note_ids'] ?? [];

        // If no delivery notes exist at all, physical items haven't been delivered
        if (empty($deliveryNoteIds)) {
            return false;
        }

        // Check if the order has been fully delivered using line-level tracking
        if ($order->getDeliveryStatus() === DeliveryStatus::FullyDelivered) {
            return true;
        }

        // For partial deliveries, check if all physical lines have been delivered
        foreach ($order->lines as $line) {
            // Skip non-product lines
            if ($line->product_id === null) {
                continue;
            }

            $product = Product::find($line->product_id);
            if ($product === null || ! $product->isPhysical()) {
                continue;
            }

            // This is a physical product line - check if it's been delivered
            $quantityDelivered = $line->quantity_delivered ?? '0.00';
            $quantity = $line->quantity ?? '0.00';

            // If this physical line hasn't been delivered at all, fail the check
            if (bccomp((string) $quantityDelivered, '0.00', 4) <= 0) {
                return false;
            }

            // If not fully delivered, that's still okay for invoicing what was delivered
            // The key requirement is that SOME delivery has happened for physical items
        }

        return true;
    }

    /**
     * Mark delivery notes associated with an order as invoiced.
     *
     * When a Sales Order is converted directly to an Invoice (not through DN consolidation),
     * this method marks all associated delivery notes as invoiced. This prevents them from
     * appearing in the DN consolidation page.
     *
     * @param  Document  $order  The source sales order
     * @param  Document  $invoice  The created invoice
     */
    private function markDeliveryNotesAsInvoiced(Document $order, Document $invoice): void
    {
        // Get delivery note IDs from order payload
        $orderPayload = $order->payload ?? [];
        $deliveryNoteIds = $orderPayload['delivery_note_ids'] ?? [];

        if (empty($deliveryNoteIds)) {
            return; // No delivery notes to update
        }

        // Mark each delivery note as invoiced
        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->where('type', DocumentType::DeliveryNote)
            ->get();

        foreach ($deliveryNotes as $dn) {
            $dnPayload = $dn->payload ?? [];

            // Skip if already invoiced (should not happen, but be safe)
            if (! empty($dnPayload['invoiced_at'])) {
                continue;
            }

            $dnPayload['invoiced_at'] = now()->toDateTimeString();
            $dnPayload['invoice_id'] = $invoice->id;
            $dnPayload['invoiced_via'] = 'order_conversion'; // Distinguish from DN consolidation

            $dn->update(['payload' => $dnPayload]);
        }
    }

    /**
     * Dispatch a DocumentConverted event for audit trail.
     *
     * This method creates and dispatches a DocumentConverted event that records
     * the conversion of one document type to another. The event is used for:
     * - Audit trail and compliance tracking
     * - Document lifecycle visibility
     * - Integration with external systems
     *
     * @param Document $source The source document being converted
     * @param Document $target The newly created document
     * @param bool $isPartial Whether this was a partial conversion
     * @param array<string, mixed> $metadata Additional metadata about the conversion
     */
    private function dispatchConversionEvent(
        Document $source,
        Document $target,
        bool $isPartial = false,
        array $metadata = [],
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
}
