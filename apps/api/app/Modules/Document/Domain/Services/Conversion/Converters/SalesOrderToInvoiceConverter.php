<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteBatchValidationException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingClaimService;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimRequest;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteClaimSet;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\Conversion\StripSubToleranceDiscountsService;
use App\Modules\Document\Domain\Services\DeliveryNoteFromDocumentFactory;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * - Claims associated delivery notes before numbering or creating the invoice
 * - Dispatches DocumentConverted event
 *
 * Claim-bearing conversions own one outer transaction. The source order header
 * is locked first, followed by any auto-created DN work, then every claimed DN
 * in ascending id order, and only then the invoice-number sequence. No invoice
 * or invoice number can exist before all delivery-note claims reserve.
 */
final class SalesOrderToInvoiceConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        private readonly GeneralLedgerService $glService,
        private readonly LocationContext $locationContext,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
        private readonly StripSubToleranceDiscountsService $discountStripper,
        private readonly DeliveryNoteFromDocumentFactory $deliveryNoteFactory,
        private readonly DeliveryNoteBillingClaimService $billingClaimService,
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
        /** @var list<string>|null $lineIds */
        $lineIds = isset($options['line_ids']) && is_array($options['line_ids'])
            ? array_values(array_map('strval', $options['line_ids']))
            : null;
        /** @var string|null $actorUserId */
        $actorUserId = $options['actor_user_id'] ?? null;

        return DB::transaction(function () use ($source, $partial, $lineIds, $actorUserId): Document {
            // Global SO lock order, Layer 0b step 2: immediately after BEGIN,
            // serialize the same order before scenario detection can take the
            // delivery-note sequence row or mutate source lines/payload.
            $order = Document::query()
                ->where('tenant_id', $source->tenant_id)
                ->where('company_id', $source->company_id)
                ->where('id', $source->id)
                ->where('type', DocumentType::SalesOrder->value)
                ->lockForUpdate()
                ->with(['lines', 'partner', 'vehicleContext'])
                ->first();

            if (! $order instanceof Document) {
                throw new \InvalidArgumentException('Source document must be a sales order');
            }

            if ($order->status === DocumentStatus::Cancelled) {
                throw new \RuntimeException('Cannot convert cancelled sales order');
            }

            if ($order->status === DocumentStatus::Draft) {
                throw new \DomainException('Sales order must be confirmed before conversion', 422);
            }

            if (! $partial && $this->isOrderFullyInvoiced($order)) {
                throw new \RuntimeException('Sales order has already been fully invoiced');
            }

            $scenario = $this->detectOrderScenario($order, $lineIds);
            $autoCreatedDeliveryNote = null;

            if (($scenario === 'products_only' || $scenario === 'mixed')
                && ! $this->hasDeliveryNotesForPhysicalItems($order)) {
                // The shared factory remains authoritative and unchanged. Its
                // DN number, rows, source-line stamps and payload linkage now
                // share this conversion's outer rollback boundary.
                $autoCreatedDeliveryNote = $this->createDeliveryNoteForOrder($order);
            }

            $deliveryNoteIds = $this->lockCompleteDeliveryNoteSet($order, $partial, $lineIds);
            $invoice = null;

            $createInvoice = function (?DeliveryNoteClaimSet $_set = null) use (
                $order,
                $partial,
                $lineIds,
                $autoCreatedDeliveryNote,
                $actorUserId,
                &$invoice,
            ): string {
                $invoice = $this->createTargetDocument($order, DocumentType::Invoice, [
                    'due_date' => now()->addDays(30),
                ]);

                // Copy lines (all or partial)
                if ($partial && $lineIds !== null) {
                    $this->copyPartialLines($order, $invoice, $lineIds);
                } else {
                    $this->copyLines($order, $invoice);
                }

                // Strip sub-tolerance discounts BEFORE recalculateTotals so the
                // resulting invoice's subtotal/tax/total correctly reflect the
                // post-strip state. Defense-in-depth at the conversion stage
                // catches anything the request validator missed (e.g. a sales
                // order created before Phase 4 was deployed).
                $this->discountStripper->stripFromConvertedDocument($order, $invoice);

                // Recalculate totals
                $this->recalculateTotals($invoice);

                // Copy vehicle context if present
                $this->copyVehicleContext($order, $invoice);

                // Transfer any prepayments from the order to the invoice
                $this->transferPrepayments($order, $invoice, $actorUserId);

                // Update order payload
                $additionalPayload = [];
                if (! $partial) {
                    $additionalPayload['fully_invoiced'] = true;
                    $additionalPayload['fully_invoiced_at'] = now()->toDateTimeString();
                }
                $this->appendToSourcePayload($order, $invoice, 'invoice_ids', $additionalPayload);

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

                return $invoice->id;
            };

            if ($deliveryNoteIds === []) {
                $createInvoice();
            } else {
                $this->billingClaimService->claim(
                    new DeliveryNoteClaimRequest(
                        $deliveryNoteIds,
                        $order->company_id,
                        DeliveryNoteBillingLane::OrderConversion,
                    ),
                    $createInvoice,
                );
            }

            if (! $invoice instanceof Document) {
                throw new \LogicException('Sales-order conversion did not create an invoice.');
            }

            // Emit only after claim finalisation. The event remains inside the
            // outer transaction, so a later failure still rolls it back.
            $this->dispatchConversionEvent($order, $invoice, $partial);

            $freshInvoice = $invoice->fresh(['lines']);
            if (! $freshInvoice instanceof Document) {
                throw new \LogicException('Created invoice could not be reloaded.');
            }

            return $freshInvoice;
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
     * Resolve and lock the complete order payload set in the global order.
     *
     * @param  list<string>|null  $lineIds
     * @return list<string>
     */
    private function lockCompleteDeliveryNoteSet(Document $order, bool $partial, ?array $lineIds): array
    {
        $payload = $order->payload ?? [];
        $rawIds = $payload['delivery_note_ids'] ?? [];
        $deliveryNoteIds = is_array($rawIds)
            ? array_values(array_unique(array_map('strval', $rawIds)))
            : [];
        sort($deliveryNoteIds, SORT_STRING);

        if ($deliveryNoteIds === []) {
            return [];
        }

        $query = Document::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->where('type', DocumentType::DeliveryNote->value)
            ->whereIn('id', $deliveryNoteIds);

        // OI-8 condition 4 reuses the existing whole-line partial conversion
        // contract. For that operation, the complete claim set is the subset
        // attributable to selected SO lines through the factory's source_line_id
        // link. Full conversion still claims every id in the order payload.
        if ($partial && $lineIds !== null) {
            $query->whereHas('lines', static function (Builder $lineQuery) use ($lineIds): void {
                $lineQuery->whereNotNull('source_line_id')->whereIn('source_line_id', $lineIds);
            });
        }

        $lockedDeliveryNotes = $query
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'document_number', 'payload']);
        $lockedIds = array_values($lockedDeliveryNotes
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all());

        if (! $partial && $lockedIds !== $deliveryNoteIds) {
            throw new \DomainException('One or more delivery notes were not found in the sales order company.', 422);
        }

        $markedIds = DB::table('delivery_note_billing_marks')
            ->where('company_id', $order->company_id)
            ->whereIn('delivery_note_id', $lockedIds)
            ->pluck('delivery_note_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->flip();
        $alreadyInvoiced = array_values($lockedDeliveryNotes
            ->filter(static fn (Document $deliveryNote): bool => ! empty(($deliveryNote->payload ?? [])['invoiced_at'])
                || $markedIds->has($deliveryNote->id))
            ->map(static fn (Document $deliveryNote): array => [
                'id' => $deliveryNote->id,
                'document_number' => $deliveryNote->document_number,
                'reason' => 'already_invoiced',
            ])
            ->values()
            ->all());

        if ($alreadyInvoiced !== []) {
            throw new DeliveryNoteBatchValidationException($alreadyInvoiced);
        }

        return $lockedIds;
    }

    /**
     * Auto-create a DRAFT delivery note for an order with physical products.
     *
     * 🔁 Wave 3 T25c: the generator itself now lives in
     * {@see DeliveryNoteFromDocumentFactory},
     * because a STANDALONE invoice needs the identical machinery under
     * `require_delivery_first` and a copy would drift. What stays here is the
     * order-specific half: resolving the location and recording the new note in
     * `order.payload['delivery_note_ids']` — the linkage shape only an order has.
     *
     * 📌 The note is created **DRAFT** (previously this docblock claimed
     * "confirmed" while the code wrote Draft — corrected in T25c). The invoice
     * can be created immediately; the note must be confirmed, which issues stock
     * and seals the fiscal chain, before the invoice can be posted.
     *
     * @return Document The created delivery note
     */
    private function createDeliveryNoteForOrder(Document $order): Document
    {
        Log::info('Auto-creating delivery note for order conversion to invoice', [
            'order_id' => $order->id,
            'order_number' => $order->document_number,
        ]);

        // 📌 DISCLOSED DEVIATION (T25c extraction, named in fix round 1 / fiscal
        // F-9). Before the extraction this path took `$order->location_id`
        // VERBATIM and only resolved a Location model on the null branch, so a
        // stale or cross-company order location was carried onto the delivery
        // note unchecked. The factory needs a real `Location`, so the id is now
        // resolved AND company-scoped — a tightening, and one that can refuse
        // where the old code proceeded. It is deliberate: a delivery note is a
        // stock-issuing document and issuing from another company's warehouse is
        // not a lesser evil than a refusal.
        //
        // Each branch gets its OWN message: "no default location" was emitted for
        // both, which sent an operator hunting for a company default when the
        // real fault was the order's own location_id.
        if ($order->location_id !== null) {
            $location = Location::query()
                ->where('company_id', $order->company_id)
                ->find($order->location_id);

            if ($location === null) {
                throw new \DomainException(
                    "The order's location does not belong to this company, so a delivery note cannot be created for it"
                );
            }
        } else {
            $location = $this->locationContext->getDefaultLocation($order->company_id);

            if ($location === null) {
                throw new \DomainException('No default location found for company');
            }
        }

        $delivery = $this->deliveryNoteFactory->createDraftFrom(
            source: $order,
            partner: $order->partner,
            location: $location,
            notes: 'Auto-created during invoice conversion - requires confirmation',
        );

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
