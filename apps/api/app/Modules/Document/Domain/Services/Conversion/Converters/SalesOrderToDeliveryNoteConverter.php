<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\SalesOrderHeaderLockException;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Converter for Sales Order to Delivery Note conversion.
 *
 * Supports both full delivery and partial delivery modes.
 *
 * Options:
 * - 'delivery_quantities' (array<string, string>): Map of order line IDs to quantities to deliver.
 *   If provided, enables partial delivery mode. If omitted, full delivery is performed.
 *
 * Validates:
 * - Source must be SalesOrder type
 * - Not cancelled
 * - Confirmed status (not draft)
 * - Not already fully delivered
 * - For partial: at least one line with quantity > 0
 * - For partial: quantities don't exceed remaining quantities
 * - Has physical products (not services-only)
 *
 * On conversion:
 * - Creates a new Delivery Note
 * - Copies lines (all for full delivery, filtered for partial)
 * - Links delivery note lines to source order lines via source_line_id
 * - Updates quantity_delivered on source order lines
 * - Copies vehicle context if present
 * - Updates order payload with delivery_note_ids
 * - For full delivery: marks order as fully_delivered
 * - Dispatches DocumentConverted event
 */
final class SalesOrderToDeliveryNoteConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        private readonly FEFOInventoryService $fefoService,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::SalesOrder;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::DeliveryNote;
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
            $errors[] = 'Sales order must be confirmed before conversion to delivery note';
        }

        // Check if order has line items
        if ($source->lines->isEmpty()) {
            $errors[] = 'Sales order must have at least one line item';
        }

        // Check if already fully delivered
        if ($source->type === DocumentType::SalesOrder) {
            try {
                if ($source->getDeliveryStatus() === DeliveryStatus::FullyDelivered) {
                    $errors[] = 'Sales order has already been fully delivered';
                }
            } catch (\InvalidArgumentException) {
                // Not a sales order - already caught above
            }
        }

        // Check if order has physical products (delivery notes are for physical products)
        if ($source->type === DocumentType::SalesOrder && $source->lines->isNotEmpty()) {
            $hasPhysical = $this->hasPhysicalProducts($source);
            if (! $hasPhysical) {
                $errors[] = 'Sales order has no physical products to deliver (services-only)';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options  Options: 'delivery_quantities' (array<string, string>)
     */
    public function convert(Document $source, array $options = []): Document
    {
        /** @var array<string, string>|null $deliveryQuantities */
        $deliveryQuantities = $options['delivery_quantities'] ?? null;

        if ($source->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Source document must be a sales order');
        }

        if ($source->status === DocumentStatus::Cancelled) {
            throw new RuntimeException('Cannot convert cancelled sales order');
        }

        if ($source->status === DocumentStatus::Draft) {
            throw new \DomainException('Sales order must be confirmed before conversion to delivery note', 422);
        }

        // Check if already fully delivered using line-level tracking
        if ($source->getDeliveryStatus() === DeliveryStatus::FullyDelivered) {
            throw new RuntimeException('Sales order has already been fully delivered');
        }

        // If delivery_quantities provided, this is a partial delivery
        if ($deliveryQuantities !== null) {
            return $this->performPartialDelivery($source, $deliveryQuantities);
        }

        return $this->performFullDelivery($source);
    }

    /**
     * Take L1 — the sales-order header row — immediately after BEGIN.
     *
     * The wave's global lock order is
     * L1 sales-order header < L2 document_sequences[delivery_note] < L3 delivery-note
     * documents rows < L4 delivery_note_billing_marks < L5 document_sequences[invoice].
     *
     * This converter used to take L2 first (createTargetDocument -> DocumentNumberingService)
     * and only reach L1 later, when appendToSourcePayload write-locked the order header —
     * the exact inversion of the L1 -> L2 order SalesOrderToInvoiceConverter takes at its
     * own BEGIN. Two concurrent requests on the SAME sales order (convert-to-invoice on an
     * order with physical lines and no delivery note yet, vs convert-to-delivery) therefore
     * formed a wait-for cycle and PostgreSQL broke it with 40P01: the invoice lane burned
     * its two retries and surfaced a 500 with a raw driver message, while this lane — which
     * has no retrier — returned a 422 carrying a deadlock string. Money-safe (both
     * transactions roll back whole, billed-once was never at risk), but a live defect and a
     * falsification of the acyclicity claim.
     *
     * Acquiring L1 here removes the back edge, so the wait-for graph is acyclic again and
     * the two lanes serialise on the order header instead of deadlocking. The lock is taken
     * for its ordering effect only: the caller's already-loaded $order (and its lines) stay
     * authoritative, so no validation or copy behaviour changes.
     *
     * The result is ASSERTED, not discarded. `->first()` returning null means the
     * predicate matched nothing — tenant/company/type drift, or a concurrent delete —
     * and in that case the statement locks NOTHING and this converter would proceed,
     * silently reinstating the exact L1<->L2 back edge the fix removes, with no
     * exception and no signal. An ordering guarantee that can silently not happen is
     * not a guarantee. Unreachable today (`convert()` has already validated the same
     * model), which is precisely why it must fail loudly if it ever becomes reachable:
     * SalesOrderHeaderLockException is a DEDICATED type that the controller's
     * convertOrderToDelivery rethrow arm carries past its catch-all to a 500-class
     * alert (bare RuntimeException could not be used — the controller lane throws it
     * for routine 422 refusals), the same disposition F-7 established. Pinned over HTTP by
     * SalesOrderBillingClaimTest::test_a_lock_order_violation_surfaces_as_a_500_class_alert_over_http. (M5-terminal treasury r3.)
     * (M5-terminal r2: treasury `R2-6` == tenancy `F-R2-3`.)
     *
     * (M5-terminal treasury F-5; total order per M5-evidence.md section 2.3.)
     */
    private function lockOrderHeader(Document $order): void
    {
        $locked = Document::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('company_id', $order->company_id)
            ->where('id', $order->id)
            ->where('type', DocumentType::SalesOrder->value)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw SalesOrderHeaderLockException::forOrder($order->id);
        }
    }

    /**
     * Perform a full delivery - all remaining quantities are delivered.
     */
    private function performFullDelivery(Document $order): Document
    {
        return DB::transaction(function () use ($order): Document {
            $this->lockOrderHeader($order);

            $delivery = $this->createTargetDocument($order, DocumentType::DeliveryNote);

            // Copy lines and update quantity_delivered on source lines
            $this->copyLinesForFullDelivery($order, $delivery);

            // Copy vehicle context if present
            $this->copyVehicleContext($order, $delivery);

            // Update order payload to track delivery notes
            $this->appendToSourcePayload($order, $delivery, 'delivery_note_ids', [
                'fully_delivered' => true,
                'fully_delivered_at' => now()->toDateTimeString(),
            ]);

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($order, $delivery);

            return $delivery;
        });
    }

    /**
     * Perform a partial delivery with specified quantities per line.
     *
     * @param  array<string, string>  $deliveryQuantities  Map of order line IDs to quantities to deliver
     */
    private function performPartialDelivery(Document $order, array $deliveryQuantities): Document
    {
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
            $this->lockOrderHeader($order);

            $delivery = $this->createTargetDocument($order, DocumentType::DeliveryNote, [
                // Don't copy totals for partial - will recalculate
                'subtotal' => null,
                'discount_amount' => null,
                'tax_amount' => null,
                'total' => null,
            ]);

            // Copy lines with specified quantities and link to source
            $this->copyLinesForPartialDelivery($order, $delivery, $deliveryQuantities);

            // Recalculate totals based on partial quantities
            $this->recalculateTotals($delivery);

            // Copy vehicle context if present
            $this->copyVehicleContext($order, $delivery);

            // Update order payload to track delivery notes
            $this->appendToSourcePayload($order, $delivery, 'delivery_note_ids');

            // Dispatch conversion event for audit trail (mark as partial)
            $this->dispatchConversionEvent($order, $delivery, true);

            return $delivery;
        });
    }

    /**
     * Copy all lines from source order to delivery note and update quantity_delivered.
     *
     * This is used for full delivery - all quantities are delivered.
     */
    private function copyLinesForFullDelivery(Document $source, Document $destination): void
    {
        $lineNumber = 0;

        foreach ($source->lines as $line) {
            // Skip non-physical products.
            //
            // NOT adopted onto PhysicalLinePredicate, and the divergence is
            // deliberate (fix round 1, inv gate F-4): this guard skips only a
            // RESOLVED non-physical product, so a line whose scoped product lookup
            // comes back NULL is still COPIED onto the delivery note. The predicate
            // would exclude it. Which behaviour is correct for an unresolvable
            // product on a full-delivery copy is a real question, not a tidy-up —
            // it is recorded in the D-19 adopting-site register and left to 3C.
            if ($line->product_id !== null) {
                // api.document.017: scope Product lookup by source tenant + company.
                $product = Product::query()
                    ->where('tenant_id', $source->tenant_id)
                    ->where('company_id', $source->company_id)
                    ->find($line->product_id);
                if ($product !== null && ! $product->isPhysical()) {
                    continue;
                }
            } else {
                $product = null;
            }

            // Check if product requires batch tracking and DN has a location
            $requiresBatch = $product !== null
                && ($product->requires_batch_tracking ?? false)
                && $destination->location_id !== null;

            if ($requiresBatch) {
                // FEFO: split into multiple DN lines (one per batch)
                $result = $this->fefoService->suggestBatchesForSale(
                    (string) $line->product_id,
                    (string) $destination->location_id,
                    (string) $line->quantity,
                );

                if ($result->fullyFulfilled) {
                    foreach ($result->suggestions as $suggestion) {
                        $lineNumber++;
                        /** @var numeric-string $batchQty */
                        $batchQty = (string) $suggestion->quantity;

                        // Calculate proportional line total
                        /** @var numeric-string $unitPrice */
                        $unitPrice = (string) $line->unit_price;
                        $lineTotal = bcmul($batchQty, $unitPrice, $this->scale());

                        DocumentLine::create([
                            'id' => Str::uuid()->toString(),
                            'document_id' => $destination->id,
                            'line_number' => $lineNumber,
                            'product_id' => $line->product_id,
                            'product_code' => $line->product_code,
                            'description' => $line->description,
                            'quantity' => $batchQty,
                            'unit_price' => $line->unit_price,
                            'discount_percent' => $line->discount_percent,
                            'discount_amount' => null, // Fixed discount not prorated per batch
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
                    $lineNumber++;
                    DocumentLine::create([
                        'id' => Str::uuid()->toString(),
                        'document_id' => $destination->id,
                        'line_number' => $lineNumber,
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

                    Log::warning('FEFO allocation failed for delivery note, falling back to non-batch line', [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'shortfall' => $result->shortfall,
                    ]);
                }
            } else {
                $lineNumber++;

                // Non-batch: create single DN line as before
                DocumentLine::create([
                    'id' => Str::uuid()->toString(),
                    'document_id' => $destination->id,
                    'line_number' => $lineNumber,
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
     *
     * @param  array<string, string>  $deliveryQuantities  Map of order line IDs to quantities to deliver
     */
    private function copyLinesForPartialDelivery(
        Document $source,
        Document $destination,
        array $deliveryQuantities
    ): void {
        $lineNumber = 0;

        foreach ($source->lines as $line) {
            /** @var numeric-string $qtyToDeliver */
            $qtyToDeliver = (string) ($deliveryQuantities[$line->id] ?? '0.00');

            // Skip lines with zero quantity
            if (bccomp($qtyToDeliver, '0.00', 4) <= 0) {
                continue;
            }

            // Check if product requires batch tracking.
            // api.document.018: scope Product lookup by source tenant + company.
            $product = $line->product_id !== null
                ? Product::query()
                    ->where('tenant_id', $source->tenant_id)
                    ->where('company_id', $source->company_id)
                    ->find($line->product_id)
                : null;
            $requiresBatch = $product !== null
                && ($product->requires_batch_tracking ?? false)
                && $destination->location_id !== null;

            if ($requiresBatch) {
                // FEFO: split into multiple DN lines (one per batch)
                $result = $this->fefoService->suggestBatchesForSale(
                    (string) $line->product_id,
                    (string) $destination->location_id,
                    (string) $qtyToDeliver,
                );

                if ($result->fullyFulfilled) {
                    foreach ($result->suggestions as $suggestion) {
                        $lineNumber++;
                        /** @var numeric-string $batchQty */
                        $batchQty = (string) $suggestion->quantity;

                        /** @var numeric-string $unitPrice */
                        $unitPrice = (string) $line->unit_price;
                        $lineTotal = bcmul($batchQty, $unitPrice, $this->scale());

                        // Apply discount if any
                        if ($line->discount_percent !== null && $line->discount_percent !== '0.00') {
                            /** @var numeric-string $discountPercent */
                            $discountPercent = (string) $line->discount_percent;
                            $discount = bcmul($lineTotal, bcdiv($discountPercent, '100', 4), $this->scale());
                            $lineTotal = bcsub($lineTotal, $discount, $this->scale());
                        }

                        DocumentLine::create([
                            'id' => Str::uuid()->toString(),
                            'document_id' => $destination->id,
                            'line_number' => $lineNumber,
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
                    $lineNumber++;

                    /** @var numeric-string $unitPrice */
                    $unitPrice = (string) $line->unit_price;
                    $lineTotal = bcmul($qtyToDeliver, $unitPrice, $this->scale());

                    if ($line->discount_percent !== null && $line->discount_percent !== '0.00') {
                        /** @var numeric-string $discountPercent */
                        $discountPercent = (string) $line->discount_percent;
                        $discount = bcmul($lineTotal, bcdiv($discountPercent, '100', 4), $this->scale());
                        $lineTotal = bcsub($lineTotal, $discount, $this->scale());
                    } elseif ($line->discount_amount !== null && $line->discount_amount !== '0.00') {
                        /** @var numeric-string $lineQty */
                        $lineQty = (string) $line->quantity;
                        /** @var numeric-string $discountAmt */
                        $discountAmt = (string) $line->discount_amount;
                        $qtyRatio = bcdiv($qtyToDeliver, $lineQty, 4);
                        $proratedDiscount = bcmul($discountAmt, $qtyRatio, $this->scale());
                        $lineTotal = bcsub($lineTotal, $proratedDiscount, $this->scale());
                    }

                    DocumentLine::create([
                        'id' => Str::uuid()->toString(),
                        'document_id' => $destination->id,
                        'line_number' => $lineNumber,
                        'product_id' => $line->product_id,
                        'product_code' => $line->product_code,
                        'description' => $line->description,
                        'quantity' => $qtyToDeliver,
                        'unit_price' => $line->unit_price,
                        'discount_percent' => $line->discount_percent,
                        'discount_amount' => $line->discount_amount,
                        'tax_rate' => $line->tax_rate,
                        'line_total' => $lineTotal,
                        'notes' => $line->notes,
                        'designation_default_snapshot' => $line->designation_default_snapshot,
                        'source_line_id' => $line->id,
                    ]);

                    Log::warning('FEFO allocation failed for partial delivery, falling back to non-batch line', [
                        'product_id' => $line->product_id,
                        'quantity' => $qtyToDeliver,
                        'shortfall' => $result->shortfall,
                    ]);
                }
            } else {
                $lineNumber++;

                // Calculate line total based on partial quantity
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $line->unit_price;
                $lineTotal = bcmul($qtyToDeliver, $unitPrice, $this->scale());

                // Apply discount if any
                if ($line->discount_percent !== null && $line->discount_percent !== '0.00') {
                    /** @var numeric-string $discountPercent */
                    $discountPercent = (string) $line->discount_percent;
                    $discount = bcmul($lineTotal, bcdiv($discountPercent, '100', 4), $this->scale());
                    $lineTotal = bcsub($lineTotal, $discount, $this->scale());
                } elseif ($line->discount_amount !== null && $line->discount_amount !== '0.00') {
                    /** @var numeric-string $lineQty */
                    $lineQty = (string) $line->quantity;
                    /** @var numeric-string $discountAmt */
                    $discountAmt = (string) $line->discount_amount;
                    $qtyRatio = bcdiv($qtyToDeliver, $lineQty, 4);
                    $proratedDiscount = bcmul($discountAmt, $qtyRatio, $this->scale());
                    $lineTotal = bcsub($lineTotal, $proratedDiscount, $this->scale());
                }

                DocumentLine::create([
                    'id' => Str::uuid()->toString(),
                    'document_id' => $destination->id,
                    'line_number' => $lineNumber,
                    'product_id' => $line->product_id,
                    'product_code' => $line->product_code,
                    'description' => $line->description,
                    'quantity' => $qtyToDeliver,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'line_total' => $lineTotal,
                    'notes' => $line->notes,
                    'designation_default_snapshot' => $line->designation_default_snapshot,
                    'source_line_id' => $line->id,
                ]);
            }

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
     * Check if the order has any physical products.
     *
     * Adopts {@see PhysicalLinePredicate} (fix round 1, inv gate F-4 / D-19). The
     * hand-rolled loop this replaces was byte-for-byte the predicate's arithmetic,
     * including the api.document.019 tenant + company scoping, which the predicate
     * takes as its optional `(tenantId, companyId)` pair — so this is an adoption,
     * not a behaviour change.
     */
    private function hasPhysicalProducts(Document $order): bool
    {
        foreach ($order->lines as $line) {
            if (PhysicalLinePredicate::forLine($line, $order->tenant_id, $order->company_id)) {
                return true;
            }
        }

        return false;
    }
}
