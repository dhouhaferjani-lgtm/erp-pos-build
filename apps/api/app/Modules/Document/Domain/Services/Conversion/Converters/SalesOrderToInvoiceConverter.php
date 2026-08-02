<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\Conversion\StripSubToleranceDiscountsService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Converter for Sales Order to Invoice conversion.
 *
 * Tunisia fiscal compliance requires:
 * - Services only: Can be invoiced directly from SO
 * - Products only: Must have delivery notes before invoicing
 * - Mixed: Physical items must be delivered before invoicing
 *
 * Options:
 * - 'partial' (bool): Whether this is a partial invoice
 * - 'line_ids' (array): For partial invoicing, the specific line IDs to invoice
 *
 * Validates:
 * - Source must be SalesOrder type
 * - Not cancelled
 * - Confirmed status (not draft)
 * - Has line items
 * - Not fully invoiced already (unless partial)
 * - Physical products have delivery notes (Tunisia compliance)
 *
 * On conversion:
 * - Creates a new Invoice with due_date (30 days from invoice date)
 * - Copies all or partial lines to the invoice
 * - Recalculates totals for partial invoices
 * - Copies vehicle context if present
 * - Transfers prepayments from order to invoice
 * - Updates order payload with invoice_ids
 * - Marks associated delivery notes as invoiced
 * - Dispatches DocumentConverted event
 */
final class SalesOrderToInvoiceConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        private readonly GeneralLedgerService $glService,
        private readonly FEFOInventoryService $fefoService,
        private readonly LocationContext $locationContext,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
        private readonly StripSubToleranceDiscountsService $discountStripper,
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::SalesOrder;
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

        if ($source->type !== DocumentType::SalesOrder) {
            $errors[] = 'Source document must be a sales order';
        }

        if ($source->status === DocumentStatus::Cancelled) {
            $errors[] = 'Cannot convert cancelled sales order';
        }

        if ($source->status === DocumentStatus::Draft) {
            $errors[] = 'Sales order must be confirmed before conversion';
        }

        // Check if order has line items
        if ($source->lines->isEmpty()) {
            $errors[] = 'Sales order must have at least one line item';
        }

        // Check if already fully invoiced
        if ($this->isOrderFullyInvoiced($source)) {
            $errors[] = 'Sales order has already been fully invoiced';
        }

        // Tunisia fiscal compliance: check physical items have delivery notes
        $scenario = $this->detectOrderScenario($source);
        if ($scenario === 'products_only' || $scenario === 'mixed') {
            if (! $this->hasDeliveryNotesForPhysicalItems($source)) {
                $errors[] = 'Physical products must be delivered before invoicing. Create a delivery note first.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options  Options: 'partial' (bool), 'line_ids' (array)
     */
    public function convert(Document $source, array $options = []): Document
    {
        /** @var bool $partial */
        $partial = $options['partial'] ?? false;
        /** @var array<int, string>|null $lineIds */
        $lineIds = $options['line_ids'] ?? null;
        /** @var string|null $actorUserId */
        $actorUserId = $options['actor_user_id'] ?? null;

        if ($source->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Source document must be a sales order');
        }

        if ($source->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled sales order');
        }

        if ($source->status === DocumentStatus::Draft) {
            throw new \DomainException('Sales order must be confirmed before conversion', 422);
        }

        // Prevent duplicate invoicing - check if order is already fully invoiced
        if (! $partial && $this->isOrderFullyInvoiced($source)) {
            throw new \RuntimeException('Sales order has already been fully invoiced');
        }

        // Tunisia fiscal compliance: auto-create delivery note if physical items exist without delivery notes
        $scenario = $this->detectOrderScenario($source, $lineIds);
        $autoCreatedDeliveryNote = null;

        if ($scenario === 'products_only' || $scenario === 'mixed') {
            if (! $this->hasDeliveryNotesForPhysicalItems($source)) {
                // Auto-create delivery note (confirmed but not delivered)
                // User can mark it as delivered when they post the invoice
                $autoCreatedDeliveryNote = $this->createDeliveryNoteForOrder($source);
            }
        }
        // scenario === 'services_only': allow direct invoicing

        return DB::transaction(function () use ($source, $partial, $lineIds, $autoCreatedDeliveryNote, $actorUserId): Document {
            $invoice = $this->createTargetDocument($source, DocumentType::Invoice, [
                'due_date' => now()->addDays(30),
            ]);

            // Copy lines (all or partial)
            if ($partial && $lineIds !== null) {
                $this->copyPartialLines($source, $invoice, $lineIds);
            } else {
                $this->copyLines($source, $invoice);
            }

            // Strip sub-tolerance discounts BEFORE recalculateTotals so the
            // resulting invoice's subtotal/tax/total correctly reflect the
            // post-strip state. Defense-in-depth at the conversion stage
            // catches anything the request validator missed (e.g. a sales
            // order created before Phase 4 was deployed).
            $this->discountStripper->stripFromConvertedDocument($source, $invoice);

            // Recalculate totals
            $this->recalculateTotals($invoice);

            // Copy vehicle context if present
            $this->copyVehicleContext($source, $invoice);

            // Transfer any prepayments from the order to the invoice
            $this->transferPrepayments($source, $invoice, $actorUserId);

            // Update order payload
            $additionalPayload = [];
            if (! $partial) {
                $additionalPayload['fully_invoiced'] = true;
                $additionalPayload['fully_invoiced_at'] = now()->toDateTimeString();
            }
            $this->appendToSourcePayload($source, $invoice, 'invoice_ids', $additionalPayload);

            // Mark associated delivery notes as invoiced
            $this->markDeliveryNotesAsInvoiced($source, $invoice);

            // Store metadata about auto-created delivery note in invoice payload
            if ($autoCreatedDeliveryNote !== null) {
                $invoicePayload = $invoice->payload ?? [];
                $invoicePayload['auto_created_delivery_note'] = [
                    'id' => $autoCreatedDeliveryNote->id,
                    'number' => $autoCreatedDeliveryNote->document_number,
                    'created_at' => $autoCreatedDeliveryNote->created_at?->toDateTimeString(),
                    'status' => 'draft_auto_created', // Draft status - requires confirmation before posting
                ];
                $invoice->update(['payload' => $invoicePayload]);
            }

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($source, $invoice, $partial);

            return $invoice;
        });
    }

    /**
     * Check if order has been fully invoiced.
     */
    private function isOrderFullyInvoiced(Document $order): bool
    {
        $payload = $order->payload ?? [];

        return $payload['fully_invoiced'] ?? false;
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

            // api.document.014/015/016: scope Product lookup by the source
            // document's tenant + company so a corrupted line.product_id
            // pointing across tenants surfaces as null and the line is
            // treated defensively as a service.
            $product = Product::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('company_id', $order->company_id)
                ->find($line->product_id);
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

            // api.document.014/015/016: scope Product lookup by the source
            // document's tenant + company so a corrupted line.product_id
            // pointing across tenants surfaces as null and the line is
            // treated defensively as a service.
            $product = Product::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('company_id', $order->company_id)
                ->find($line->product_id);
            if ($product === null || ! $product->isPhysical()) {
                continue;
            }

            // This is a physical product line - check if it's been delivered
            $quantityDelivered = $line->quantity_delivered ?? '0.00';

            // If this physical line hasn't been delivered at all, fail the check
            if (bccomp((string) $quantityDelivered, '0.00', 4) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transfer prepayments from a sales order to the resulting invoice.
     */
    private function transferPrepayments(Document $order, Document $invoice, ?string $actorUserId): void
    {
        // Get all payment allocations from the sales order
        $allocations = PaymentAllocation::where('document_id', $order->id)->get();

        if ($allocations->isEmpty()) {
            return; // No prepayments to transfer
        }

        // Calculate total prepaid amount
        $totalPrepaid = '0.00';
        foreach ($allocations as $allocation) {
            $totalPrepaid = bcadd($totalPrepaid, (string) $allocation->amount, $this->scale());
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
        $newBalanceDue = bcsub($invoiceTotal, $totalPrepaid, $this->scale());
        if (bccomp($newBalanceDue, '0.00', $this->scale()) < 0) {
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
        if (bccomp($totalPrepaid, '0.00', $this->scale()) > 0) {
            try {
                $this->glService->clearCustomerAdvanceToReceivable(
                    $invoice->company_id,
                    $invoice->partner_id,
                    $invoice->id,
                    $totalPrepaid,
                    now(),
                    "Prepayment applied from order {$order->document_number} to invoice {$invoice->document_number}",
                    $actorUserId,
                    (string) $invoice->currency,
                );
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                // If GL clearing cannot be created, log warning but don't fail the conversion.
                Log::warning(
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
     * Mark delivery notes associated with an order as invoiced.
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
     * Auto-create a delivery note for an order with physical products.
     *
     * Creates delivery note in "confirmed" status but with delivery_status "not_delivered".
     * This allows the invoice to be created immediately while still enforcing Tunisia
     * compliance - the delivery note must be marked as delivered before invoice can be posted.
     *
     * @return Document The created delivery note
     */
    private function createDeliveryNoteForOrder(Document $order): Document
    {
        Log::info('Auto-creating delivery note for order conversion to invoice', [
            'order_id' => $order->id,
            'order_number' => $order->document_number,
        ]);

        // Get location_id from order, or fallback to company's default location
        $locationId = $order->location_id;

        if ($locationId === null) {
            $defaultLocation = $this->locationContext->getDefaultLocation($order->company_id);

            if ($defaultLocation === null) {
                throw new \DomainException('No default location found for company');
            }

            $locationId = $defaultLocation->id;
        }

        // Create delivery note document in DRAFT status
        // User must explicitly confirm via modal before posting invoice
        /** @phpstan-ignore argument.type */
        $delivery = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $order->tenant_id,
            'company_id' => $order->company_id,
            'location_id' => $locationId,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft, // Draft - requires explicit confirmation
            'document_number' => $this->numberingService->generateNumber($order->tenant_id, $order->company_id, DocumentType::DeliveryNote),
            'document_date' => now(),
            'partner_id' => $order->partner_id,
            'partner_name' => $order->partner->name,
            'partner_address' => $order->partner->street_address,
            'currency' => $order->currency,
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'total' => $order->total,
            'notes' => 'Auto-created during invoice conversion - requires confirmation',
            'source_document_id' => $order->id,
            'payload' => [
                'auto_created' => true, // Flag for batch confirmation
            ],
        ]);

        // Copy lines (only physical products), with FEFO splitting for batch-tracked products
        $dnLineNumber = 0;
        foreach ($order->lines as $line) {
            // Skip non-physical products
            $product = null;
            if ($line->product_id !== null) {
                // api.document.014/015/016: scope Product lookup by the source
                // document's tenant + company so a corrupted line.product_id
                // pointing across tenants surfaces as null and the line is
                // treated defensively as a service.
                $product = Product::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('company_id', $order->company_id)
                    ->find($line->product_id);
                if ($product !== null && ! $product->isPhysical()) {
                    continue; // Skip services
                }
            }

            // Check if product requires batch tracking
            $requiresBatch = $product !== null
                && ($product->requires_batch_tracking ?? false);

            if ($requiresBatch) {
                // FEFO: split into multiple DN lines (one per batch)
                $result = $this->fefoService->suggestBatchesForSale(
                    (string) $line->product_id,
                    (string) $locationId,
                    (float) $line->quantity,
                );

                if ($result->fullyFulfilled) {
                    foreach ($result->suggestions as $suggestion) {
                        $dnLineNumber++;
                        /** @var numeric-string $batchQty */
                        $batchQty = (string) $suggestion->quantity;
                        /** @var numeric-string $unitPrice */
                        $unitPrice = (string) $line->unit_price;
                        $lineTotal = bcmul($batchQty, $unitPrice, $this->scale());

                        DocumentLine::create([
                            'id' => Str::uuid()->toString(),
                            'document_id' => $delivery->id,
                            'line_number' => $dnLineNumber,
                            'product_id' => $line->product_id,
                            'product_code' => $line->product_code,
                            'description' => $line->description,
                            'quantity' => $batchQty,
                            'unit_price' => $line->unit_price,
                            'discount_percent' => $line->discount_percent,
                            'discount_amount' => null,
                            'tax_rate' => $line->tax_rate,
                            'line_total' => $lineTotal,
                            'notes' => $line->notes,
                            'designation_default_snapshot' => $line->designation_default_snapshot,
                            'source_line_id' => $line->id,
                            'batch_id' => $suggestion->batch->id,
                        ]);
                    }
                } else {
                    // Fallback: create single line without batch (insufficient batch stock)
                    $dnLineNumber++;
                    DocumentLine::create([
                        'id' => Str::uuid()->toString(),
                        'document_id' => $delivery->id,
                        'line_number' => $dnLineNumber,
                        'product_id' => $line->product_id,
                        'product_code' => $line->product_code,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'discount_percent' => $line->discount_percent,
                        'discount_amount' => $line->discount_amount,
                        'tax_rate' => $line->tax_rate,
                        'line_total' => $line->line_total ?? '0.00',
                        'notes' => $line->notes,
                        'designation_default_snapshot' => $line->designation_default_snapshot,
                        'source_line_id' => $line->id,
                    ]);

                    Log::warning('FEFO allocation failed for auto-created DN, falling back to non-batch line', [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'shortfall' => $result->shortfall,
                    ]);
                }
            } else {
                $dnLineNumber++;
                DocumentLine::create([
                    'id' => Str::uuid()->toString(),
                    'document_id' => $delivery->id,
                    'line_number' => $dnLineNumber,
                    'product_id' => $line->product_id,
                    'product_code' => $line->product_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'line_total' => $line->line_total ?? '0.00',
                    'notes' => $line->notes,
                    'designation_default_snapshot' => $line->designation_default_snapshot,
                    'source_line_id' => $line->id,
                ]);
            }

            // Update order line's quantity_delivered
            $line->update([
                'quantity_delivered' => $line->quantity,
            ]);
        }

        // Copy vehicle context if present
        if ($order->vehicleContext !== null) {
            $delivery->vehicleContext()->create([
                'id' => Str::uuid()->toString(),
                'vehicle_id' => $order->vehicleContext->vehicle_id,
                'vehicle_snapshot' => $order->vehicleContext->vehicle_snapshot,
                'mileage_at_service' => $order->vehicleContext->mileage_at_service,
                'context_data' => $order->vehicleContext->context_data,
            ]);
        }

        // Update order payload to track delivery note
        $orderPayload = $order->payload ?? [];
        $deliveryNoteIds = $orderPayload['delivery_note_ids'] ?? [];
        $deliveryNoteIds[] = $delivery->id;
        $orderPayload['delivery_note_ids'] = $deliveryNoteIds;
        $orderPayload['auto_created_delivery_note'] = true; // Flag for UI
        $order->update(['payload' => $orderPayload]);

        Log::info('Auto-created delivery note', [
            'delivery_note_id' => $delivery->id,
            'delivery_note_number' => $delivery->document_number,
            'order_id' => $order->id,
        ]);

        return $delivery;
    }
}
