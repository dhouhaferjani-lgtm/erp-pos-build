<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptResult;
use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Inventory\ReceiptLineGuardInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

/**
 * GoodsReceiptService - Handles goods receipt for purchase orders
 *
 * This service manages the receiving of goods from purchase orders,
 * including partial receipts, WAC updates, and stock level changes.
 */
final class GoodsReceiptService implements ReceiptLineGuardInterface
{
    private const int QUANTITY_SCALE = 4;

    private const int COST_SCALE = 6;

    private const int WORKING_SCALE = 10;

    public function __construct(
        private readonly WeightedAverageCostService $wacService,
        private readonly LandedCostService $landedCostService,
        private readonly BatchStockService $batchStockService,
        private readonly ProductCostLock $costLock,
        private readonly DocumentNumberingService $numberingService,
        private readonly ReceiptBatchCostAllocator $receiptBatchCostAllocator,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly GeneralLedgerService $generalLedgerService,
    ) {}

    /**
     * Receive goods for a purchase order.
     *
     * @param  Document  $purchaseOrder  The confirmed purchase order
     * @param  array<string, string>  $receivedQuantities  Map of line_id => paid quantity to receive
     * @param  array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>  $batchData  Optional batch data per line_id
     * @param  array<string, string>  $freeQuantities  Map of line_id => free quantity to receive
     * @param  array<string, string>  $receivedUnitPrices  Map of line_id => received unit price override
     *
     * @throws \DomainException If PO is not in valid state for receiving
     */
    public function receiveGoods(
        Document $purchaseOrder,
        array $receivedQuantities,
        array $batchData = [],
        array $freeQuantities = [],
        array $receivedUnitPrices = [],
        ?string $priceOverrideReason = null,
        ?string $actorId = null,
        ?string $destinationLocationId = null,
    ): GoodsReceiptResult {
        return DB::transaction(function () use ($purchaseOrder, $receivedQuantities, $batchData, $freeQuantities, $receivedUnitPrices, $priceOverrideReason, $actorId, $destinationLocationId): GoodsReceiptResult {
            $draft = $this->createDraft(
                $purchaseOrder,
                $receivedQuantities,
                $batchData,
                $freeQuantities,
                $receivedUnitPrices,
                $priceOverrideReason,
                (string) ($actorId ?? ''),
                null,
                null,
                $destinationLocationId,
            );

            $posted = $this->post($draft, (string) ($actorId ?? ''), $destinationLocationId);

            /** @var Document $freshOrder */
            $freshOrder = $posted->purchaseOrder->fresh(['lines']);

            /** @var GoodsReceipt $freshReceipt */
            $freshReceipt = $posted->fresh(['lines']);

            return new GoodsReceiptResult($freshOrder, $freshReceipt);
        });
    }

    /**
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>  $batchData
     * @param  array<string, string>  $freeQuantities
     * @param  array<string, string>  $receivedUnitPrices
     */
    public function createDraft(
        Document $po,
        array $receivedQuantities,
        array $batchData,
        array $freeQuantities,
        array $receivedUnitPrices,
        ?string $priceOverrideReason,
        string $actorId,
        ?string $externalReference = null,
        ?string $externalDate = null,
        ?string $destinationLocationId = null,
    ): GoodsReceipt {
        $this->assertReceivablePurchaseOrder($po);

        return DB::transaction(function () use ($po, $receivedQuantities, $batchData, $freeQuantities, $receivedUnitPrices, $priceOverrideReason, $actorId, $externalReference, $externalDate, $destinationLocationId): GoodsReceipt {
            $priceScale = $this->scaleResolver->getScale((string) ($po->currency ?? 'TND'));
            $hasDraftLines = false;

            $receipt = GoodsReceipt::create([
                'tenant_id' => $po->tenant_id,
                'company_id' => $po->company_id,
                'purchase_order_id' => $po->id,
                'location_id' => $destinationLocationId,
                'receipt_number' => null,
                'status' => GoodsReceiptStatus::Draft,
                'received_at' => now(),
                'received_by' => $actorId !== '' ? $actorId : null,
                'external_reference' => $externalReference,
                'external_date' => $externalDate,
                'payload' => [
                    'batch_data' => $batchData,
                    'external_reference' => $externalReference,
                    'external_date' => $externalDate,
                ],
            ]);

            foreach ($po->lines as $line) {
                /** @var numeric-string $qtyToReceive */
                $qtyToReceive = $receivedQuantities[$line->id] ?? '0.00';
                /** @var numeric-string $freeQtyToReceive */
                $freeQtyToReceive = $freeQuantities[$line->id] ?? '0.00';

                if (bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) <= 0 && bccomp($freeQtyToReceive, '0.00', self::QUANTITY_SCALE) <= 0) {
                    continue;
                }

                if ($line->product_id === null) {
                    continue;
                }

                $this->assertQuantitiesWithinRemaining($line, $qtyToReceive, $freeQtyToReceive);

                $receivedUnitPrice = null;
                if (array_key_exists((string) $line->id, $receivedUnitPrices) && bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) > 0) {
                    $rawReceivedUnitPrice = (string) $receivedUnitPrices[(string) $line->id];
                    if (! is_numeric($rawReceivedUnitPrice) || bccomp($rawReceivedUnitPrice, '0', $priceScale) <= 0) {
                        throw new \DomainException("received_unit_price must be greater than zero for line {$line->id}.");
                    }
                    $receivedUnitPrice = CurrencyScale::bcround($rawReceivedUnitPrice, $priceScale);
                }

                GoodsReceiptLine::create([
                    'tenant_id' => $po->tenant_id,
                    'company_id' => $po->company_id,
                    'goods_receipt_id' => $receipt->id,
                    'po_line_id' => $line->id,
                    'product_id' => (string) $line->product_id,
                    'variant_id' => $line->variant_id ?? null,
                    'received_qty' => $qtyToReceive,
                    'free_qty' => $freeQtyToReceive,
                    'received_unit_price' => $receivedUnitPrice,
                    'landed_unit_cost' => null,
                    'accrual_unit_cost' => null,
                    'effective_unit_cost' => null,
                    'movement_id' => null,
                    'free_movement_id' => null,
                    'quantity_invoiced' => '0.0000',
                    'price_override_by' => null,
                    'price_override_at' => null,
                    'price_override_old_basis' => null,
                    'price_override_reason' => $receivedUnitPrice !== null ? $priceOverrideReason : null,
                ]);
                $hasDraftLines = true;
            }

            if (! $hasDraftLines) {
                throw new \DomainException('No items to receive. Please specify quantities to receive.');
            }

            /** @var GoodsReceipt $fresh */
            $fresh = $receipt->fresh(['lines', 'purchaseOrder.lines']);

            return $fresh;
        });
    }

    public function post(GoodsReceipt $receipt, string $actorId, ?string $destinationLocationId = null, bool $failClosedGrir = false): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $actorId, $destinationLocationId, $failClosedGrir): GoodsReceipt {
            /** @var GoodsReceipt $lockedReceipt */
            $lockedReceipt = GoodsReceipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReceipt->status !== GoodsReceiptStatus::Draft) {
                throw new \DomainException("Goods receipt {$lockedReceipt->id} must be Draft before posting.");
            }

            $lockedReceipt->load(['lines', 'purchaseOrder.lines']);
            $purchaseOrder = $lockedReceipt->purchaseOrder;
            $this->assertReceivablePurchaseOrder($purchaseOrder);
            $this->assertCanApplyDraftPriceOverrides($lockedReceipt, $actorId);

            // Get the default location for this company
            $location = $this->resolveDestinationLocation($purchaseOrder, $destinationLocationId ?? $lockedReceipt->location_id);
            $lockedReceipt->location_id = $location->id;
            $lockedReceipt->save();

            // Re-allocate costs if they were modified after initial confirmation
            if ($this->landedCostService->hasAllocatedCosts($purchaseOrder)) {
                $this->landedCostService->reallocateCosts($purchaseOrder);
                /** @var Document $purchaseOrder */
                $purchaseOrder = $purchaseOrder->fresh(['lines']);
            }

            /** @var list<string> $productIds */
            $productIds = $purchaseOrder->lines
                ->pluck('product_id')
                ->filter()
                ->unique()
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            return $this->costLock->acquire(
                $purchaseOrder->tenant_id,
                $purchaseOrder->company_id,
                $productIds,
                function () use ($purchaseOrder, $lockedReceipt, $actorId, $location, $failClosedGrir): GoodsReceipt {
                    // Re-read PO lines INSIDE the cost lock: they were loaded before
                    // acquisition, and a concurrent post() for the same PO may have
                    // advanced quantity_received meanwhile — the over-receive
                    // re-validation must see post-serialization counters or the
                    // bcadd counter write becomes a lost update.
                    $purchaseOrder->load('lines');
                    $input = $this->draftInput($lockedReceipt);
                    $lockedReceipt->forceFill([
                        'receipt_number' => $this->numberingService->generateForKey(
                            $purchaseOrder->tenant_id,
                            $purchaseOrder->company_id,
                            'goods_receipt',
                            'GRN',
                        ),
                        'status' => GoodsReceiptStatus::Posted,
                        'received_by' => $actorId !== '' ? $actorId : $lockedReceipt->received_by,
                    ])->save();

                    $batchFreightShares = $this->receiptBatchCostAllocator->allocate(
                        $purchaseOrder,
                        $input['receivedQuantities'],
                        $input['receivedUnitPrices'],
                    );

                    $this->processReceiptLines(
                        $purchaseOrder,
                        $lockedReceipt,
                        $input['receivedQuantities'],
                        $input['batchData'],
                        $input['freeQuantities'],
                        $input['receivedUnitPrices'],
                        $batchFreightShares,
                        $input['priceOverrideReason'],
                        $actorId,
                        $location,
                        $failClosedGrir,
                    );

                    /** @var GoodsReceipt $freshReceipt */
                    $freshReceipt = $lockedReceipt->fresh(['lines', 'purchaseOrder.lines']);

                    return $freshReceipt;
                }
            );
        });
    }

    private function assertReceivablePurchaseOrder(Document $purchaseOrder): void
    {
        if ($purchaseOrder->type !== DocumentType::PurchaseOrder) {
            throw new \DomainException('Only purchase orders can receive goods');
        }

        if ($purchaseOrder->status !== DocumentStatus::Confirmed) {
            throw new \DomainException('Purchase order must be confirmed before receiving goods');
        }
    }

    /**
     * @param  numeric-string  $qtyToReceive
     * @param  numeric-string  $freeQtyToReceive
     */
    private function assertQuantitiesWithinRemaining(DocumentLine $line, string $qtyToReceive, string $freeQtyToReceive): void
    {
        /** @var numeric-string $alreadyReceived */
        $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
        $remaining = bcsub((string) $line->quantity, $alreadyReceived, self::QUANTITY_SCALE);

        if (bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) > 0 && bccomp($qtyToReceive, $remaining, self::QUANTITY_SCALE) > 0) {
            throw new \DomainException(
                "Cannot receive more than ordered for line {$line->id}. ".
                "Ordered: {$line->quantity}, Already received: {$alreadyReceived}, Requested: {$qtyToReceive}"
            );
        }

        /** @var numeric-string $alreadyFreeReceived */
        $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');
        $freeRemaining = bcsub((string) ($line->free_quantity ?? '0.00'), $alreadyFreeReceived, self::QUANTITY_SCALE);

        if (bccomp($freeQtyToReceive, '0.00', self::QUANTITY_SCALE) > 0 && bccomp($freeQtyToReceive, $freeRemaining, self::QUANTITY_SCALE) > 0) {
            throw new \DomainException(
                "Cannot receive more free quantity than ordered for line {$line->id}. ".
                "Free ordered: {$line->free_quantity}, Already received: {$alreadyFreeReceived}, Requested: {$freeQtyToReceive}"
            );
        }
    }

    private function assertCanApplyDraftPriceOverrides(GoodsReceipt $receipt, string $actorId): void
    {
        $hasPriceOverride = $receipt->lines->contains(
            static fn (GoodsReceiptLine $line): bool => $line->received_unit_price !== null,
        );

        if (! $hasPriceOverride) {
            return;
        }

        /** @var User|null $actor */
        $actor = $actorId !== '' ? User::find($actorId) : null;
        if ($actor === null || ! $actor->can('goods-receipt.edit-price')) {
            throw new \DomainException('User is not allowed to apply goods receipt price overrides.');
        }
    }

    /**
     * @return array{
     *   receivedQuantities: array<string, string>,
     *   freeQuantities: array<string, string>,
     *   receivedUnitPrices: array<string, string>,
     *   batchData: array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>,
     *   priceOverrideReason: ?string
     * }
     */
    private function draftInput(GoodsReceipt $receipt): array
    {
        $receivedQuantities = [];
        $freeQuantities = [];
        $receivedUnitPrices = [];
        $priceOverrideReason = null;

        /** @var GoodsReceiptLine $line */
        foreach ($receipt->lines as $line) {
            $receivedQuantities[$line->po_line_id] = (string) $line->received_qty;
            $freeQuantities[$line->po_line_id] = (string) $line->free_qty;
            if ($line->received_unit_price !== null) {
                $receivedUnitPrices[$line->po_line_id] = (string) $line->received_unit_price;
                $priceOverrideReason ??= $line->price_override_reason;
            }
        }

        $payload = $receipt->payload ?? [];
        $batchData = is_array($payload['batch_data'] ?? null) ? $payload['batch_data'] : [];

        return [
            'receivedQuantities' => $receivedQuantities,
            'freeQuantities' => $freeQuantities,
            'receivedUnitPrices' => $receivedUnitPrices,
            'batchData' => $batchData,
            'priceOverrideReason' => $priceOverrideReason,
        ];
    }

    /**
     * @param  numeric-string  $receivedQty
     * @param  numeric-string  $unitCost
     */
    private function postFailClosedGrirIfRequested(
        bool $failClosedGrir,
        string $companyId,
        string $movementId,
        string $receivedQty,
        string $unitCost,
        string $currency,
    ): void {
        if (! $failClosedGrir) {
            return;
        }

        $this->generalLedgerService->createGoodsReceiptGrIrEntry(
            $companyId,
            $movementId,
            $receivedQty,
            $unitCost,
            $currency,
        );
    }

    /**
     * Process the per-line receipt loop. Runs INSIDE the receiveGoods transaction
     * AND inside the up-front sorted ProductCostLock acquisition, so the canonical
     * lock order (advisory -> stock_level -> product) is preserved and no product
     * row lock is held before recordPurchase() is invoked.
     *
     * @param  array<string, string>  $receivedQuantities
     * @param  array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>  $batchData
     * @param  array<string, string>  $freeQuantities
     * @param  array<string, string>  $receivedUnitPrices
     * @param  array<string, string>  $batchFreightShares
     *
     * @throws \DomainException
     */
    private function processReceiptLines(
        Document $purchaseOrder,
        GoodsReceipt $receipt,
        array $receivedQuantities,
        array $batchData,
        array $freeQuantities,
        array $receivedUnitPrices,
        array $batchFreightShares,
        ?string $priceOverrideReason,
        ?string $actorId,
        Location $location,
        bool $failClosedGrir = false,
    ): GoodsReceiptResult {
        $hasReceivedItems = false;
        $priceScale = $this->scaleResolver->getScale((string) ($purchaseOrder->currency ?? 'TND'));
        $hasBatchFreightPool = $this->hasPositiveFreightPool($batchFreightShares);
        $receipt->loadMissing('lines');
        $receiptLinesByPoLine = $receipt->lines->keyBy('po_line_id');

        foreach ($purchaseOrder->lines as $line) {
            /** @var numeric-string $qtyToReceive */
            $qtyToReceive = $receivedQuantities[$line->id] ?? '0.00';
            /** @var numeric-string $freeQtyToReceive */
            $freeQtyToReceive = $freeQuantities[$line->id] ?? '0.00';

            if (bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) <= 0 && bccomp($freeQtyToReceive, '0.00', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            // Validate not over-receiving paid/free units.
            $this->assertQuantitiesWithinRemaining($line, $qtyToReceive, $freeQtyToReceive);
            /** @var numeric-string $alreadyReceived */
            $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
            /** @var numeric-string $alreadyFreeReceived */
            $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');

            // Skip non-physical products (services)
            if ($line->product_id === null) {
                continue;
            }

            // Scope by the purchase order's tenant + company so a forged
            // line.product_id (cross-tenant) cannot resolve to a foreign
            // product. Loaded UNLOCKED on purpose: recordPurchase() below
            // re-fetches and locks the product row itself in the canonical
            // order (advisory -> stock_level -> product). Holding a product
            // row lock here would invert that order and deadlock. We only
            // need the object for isPhysical()/requires_batch_tracking checks
            // and to pass into recordPurchase().
            $product = Product::query()
                ->where('tenant_id', $purchaseOrder->tenant_id)
                ->where('company_id', $purchaseOrder->company_id)
                ->find($line->product_id);
            if ($product === null || ! $product->isPhysical()) {
                continue;
            }

            if (($product->requires_batch_tracking ?? false) && ! isset($batchData[$line->id])) {
                throw new \DomainException("Batch data is required for batch-tracked product {$product->id}");
            }

            // Use landed cost from the PO line (includes allocated additional costs).
            // Keep as a numeric string — no float cast (precision contract P0-1).
            $unitCostStr = (string) ($line->landed_unit_cost ?? $line->unit_price);
            $oldBasis = CurrencyScale::bcround($unitCostStr, self::COST_SCALE);
            $batchFreightShare = CurrencyScale::bcround((string) ($batchFreightShares[(string) $line->id] ?? '0'), self::COST_SCALE);
            $hasReceivedPriceOverride = bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) > 0
                && array_key_exists((string) $line->id, $receivedUnitPrices);
            $receivedUnitPrice = null;
            if ($hasReceivedPriceOverride) {
                $rawReceivedUnitPrice = (string) $receivedUnitPrices[(string) $line->id];
                if (! is_numeric($rawReceivedUnitPrice) || bccomp($rawReceivedUnitPrice, '0', $priceScale) <= 0) {
                    throw new \DomainException("received_unit_price must be greater than zero for line {$line->id}.");
                }
                $receivedUnitPrice = CurrencyScale::bcround($rawReceivedUnitPrice, $priceScale);
            }
            $baseUnitCost = $hasReceivedPriceOverride
                ? CurrencyScale::bcround((string) $receivedUnitPrice, self::COST_SCALE)
                : ($hasBatchFreightPool
                    ? CurrencyScale::bcround((string) $line->unit_price, self::COST_SCALE)
                    : $oldBasis);
            $landedUnitCost = $this->landedUnitCostForReceipt($qtyToReceive, $baseUnitCost, $batchFreightShare);
            $movementId = null;
            $freeMovementId = null;

            // Thread variant_id from the PO line (Task 20). null for non-variant
            // products → product-level stock (backward compat). WAC stays
            // product-grain (§6.7); the variant only scopes the physical row.
            $variantId = $line->variant_id ?? null;

            $batch = null;
            if (isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false)) {
                $lineBatch = $batchData[$line->id];
                $batch = $this->batchStockService->findOrCreateBatch(
                    companyId: $purchaseOrder->company_id,
                    tenantId: $purchaseOrder->tenant_id,
                    productId: (string) $product->id,
                    batchNumber: $lineBatch['batch_number'],
                    expiryDate: $lineBatch['expiry_date'],
                    manufacturingDate: $lineBatch['manufacturing_date'] ?? null,
                );
            }

            if (bccomp($freeQtyToReceive, '0.00', self::QUANTITY_SCALE) > 0) {
                $freeMovement = $this->wacService->recordPurchase(
                    product: $product,
                    location: $location,
                    quantity: $freeQtyToReceive,
                    landedUnitCost: '0',
                    reference: $purchaseOrder->document_number,
                    referenceType: 'Document',
                    referenceId: $purchaseOrder->id,
                    variantId: $variantId,
                );

                event(new GoodsReceived(
                    tenantId: $purchaseOrder->tenant_id,
                    companyId: $purchaseOrder->company_id,
                    productId: (string) $product->id,
                    locationId: (string) $location->id,
                    poLineId: (string) $line->id,
                    movementId: (string) $freeMovement->id,
                    receivedQty: $freeQtyToReceive,
                    unitCost: '0',
                    currency: (string) ($purchaseOrder->currency ?? 'TND'),
                ));
                $this->postFailClosedGrirIfRequested(
                    $failClosedGrir,
                    $purchaseOrder->company_id,
                    (string) $freeMovement->id,
                    $freeQtyToReceive,
                    '0',
                    (string) ($purchaseOrder->currency ?? 'TND'),
                );
                $freeMovementId = (string) $freeMovement->id;

                if ($batch !== null) {
                    $this->batchStockService->receiveBatchStock(
                        tenantId: $purchaseOrder->tenant_id,
                        batchId: (int) $batch->id,
                        locationId: (string) $location->id,
                        quantity: $freeQtyToReceive,
                        movementId: $freeMovement->id,
                    );
                }

                $line->free_quantity_received = bcadd($alreadyFreeReceived, $freeQtyToReceive, self::QUANTITY_SCALE);
            }

            if (bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) > 0) {
                // Record purchase with WAC update and audit trail.
                $movement = $this->wacService->recordPurchase(
                    product: $product,
                    location: $location,
                    quantity: $qtyToReceive,
                    landedUnitCost: $landedUnitCost,
                    reference: $purchaseOrder->document_number,
                    referenceType: 'Document',
                    referenceId: $purchaseOrder->id,
                    variantId: $variantId,
                );

                event(new GoodsReceived(
                    tenantId: $purchaseOrder->tenant_id,
                    companyId: $purchaseOrder->company_id,
                    productId: (string) $product->id,
                    locationId: (string) $location->id,
                    poLineId: (string) $line->id,
                    movementId: (string) $movement->id,
                    receivedQty: $qtyToReceive,
                    unitCost: $landedUnitCost,
                    currency: (string) ($purchaseOrder->currency ?? 'TND'),
                ));
                $this->postFailClosedGrirIfRequested(
                    $failClosedGrir,
                    $purchaseOrder->company_id,
                    (string) $movement->id,
                    $qtyToReceive,
                    $landedUnitCost,
                    (string) ($purchaseOrder->currency ?? 'TND'),
                );
                $movementId = (string) $movement->id;

                if ($batch !== null) {
                    $this->batchStockService->receiveBatchStock(
                        tenantId: $purchaseOrder->tenant_id,
                        batchId: (int) $batch->id,
                        locationId: (string) $location->id,
                        quantity: $qtyToReceive,
                        movementId: $movement->id,
                    );
                }

                // Record the receipt-time 408 accrual basis (immutable after first paid receipt).
                // SupplierInvoicePostingService::post() asserts against this to detect
                // post-receipt landed-cost reallocations that would leave a 408 residue.
                if ($line->accrual_unit_cost === null) {
                    $line->accrual_unit_cost = $landedUnitCost;
                }

                $line->quantity_received = bcadd($alreadyReceived, $qtyToReceive, self::QUANTITY_SCALE);
            }

            if ($batch !== null) {
                $line->batch_id = $batch->id;
            }

            // The latest receipt destination owns the unreceived remainder for this PO line.
            $line->location_id = $location->id;

            $line->save();

            /** @var GoodsReceiptLine|null $receiptLine */
            $receiptLine = $receiptLinesByPoLine->get((string) $line->id);
            $receiptLinePayload = [
                'tenant_id' => $purchaseOrder->tenant_id,
                'company_id' => $purchaseOrder->company_id,
                'goods_receipt_id' => $receipt->id,
                'po_line_id' => $line->id,
                'product_id' => (string) $product->id,
                'variant_id' => $variantId,
                'received_qty' => $qtyToReceive,
                'free_qty' => $freeQtyToReceive,
                'received_unit_price' => $receivedUnitPrice,
                'landed_unit_cost' => CurrencyScale::bcround($landedUnitCost, self::COST_SCALE),
                'accrual_unit_cost' => CurrencyScale::bcround($landedUnitCost, self::COST_SCALE),
                'effective_unit_cost' => $this->effectiveUnitCost(
                    $qtyToReceive,
                    $freeQtyToReceive,
                    CurrencyScale::bcround($landedUnitCost, self::COST_SCALE),
                ),
                'movement_id' => $movementId,
                'free_movement_id' => $freeMovementId,
                'quantity_invoiced' => '0.0000',
                'price_override_by' => $hasReceivedPriceOverride ? $actorId : null,
                'price_override_at' => $hasReceivedPriceOverride ? now() : null,
                'price_override_old_basis' => $hasReceivedPriceOverride ? $oldBasis : null,
                'price_override_reason' => $hasReceivedPriceOverride ? $priceOverrideReason : null,
            ];

            if ($receiptLine === null) {
                GoodsReceiptLine::create($receiptLinePayload);
            } else {
                $receiptLine->forceFill($receiptLinePayload)->save();
            }

            $hasReceivedItems = true;
        }

        if (! $hasReceivedItems) {
            throw new \DomainException('No items to receive. Please specify quantities to receive.');
        }

        // Check if fully received
        $fullyReceived = $this->isFullyReceived($purchaseOrder);

        // Update PO status and metadata
        $purchaseOrder->update([
            'status' => $fullyReceived ? DocumentStatus::Received : $purchaseOrder->status,
            'payload' => array_merge($purchaseOrder->payload ?? [], [
                'last_goods_receipt_at' => now()->toDateTimeString(),
                'fully_received' => $fullyReceived,
                'goods_received_at' => $fullyReceived ? now()->toDateTimeString() : null,
            ]),
        ]);

        /** @var Document $freshOrder */
        $freshOrder = $purchaseOrder->fresh(['lines']);

        /** @var GoodsReceipt $freshReceipt */
        $freshReceipt = $receipt->fresh(['lines']);

        return new GoodsReceiptResult($freshOrder, $freshReceipt);
    }

    /**
     * Receive all remaining items for a purchase order.
     *
     * @param  Document  $purchaseOrder  The confirmed purchase order
     */
    public function receiveAll(Document $purchaseOrder, ?string $actorId = null, ?string $destinationLocationId = null): GoodsReceiptResult
    {
        $receivedQuantities = [];

        foreach ($purchaseOrder->lines as $line) {
            $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
            $remaining = bcsub((string) $line->quantity, $alreadyReceived, self::QUANTITY_SCALE);

            if (bccomp($remaining, '0.00', self::QUANTITY_SCALE) > 0) {
                $receivedQuantities[$line->id] = $remaining;
            }
        }

        $freeQuantities = [];

        foreach ($purchaseOrder->lines as $line) {
            $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');
            $freeRemaining = bcsub((string) ($line->free_quantity ?? '0.00'), $alreadyFreeReceived, self::QUANTITY_SCALE);

            if (bccomp($freeRemaining, '0.00', self::QUANTITY_SCALE) > 0) {
                $freeQuantities[$line->id] = $freeRemaining;
            }
        }

        return $this->receiveGoods($purchaseOrder, $receivedQuantities, [], $freeQuantities, [], null, $actorId, $destinationLocationId);
    }

    /**
     * @param  list<string>  $poLineIds
     * @return list<string>
     */
    public function poLineIdsWithReceipts(array $poLineIds): array
    {
        if ($poLineIds === []) {
            return [];
        }

        /** @var list<string> $lockedIds */
        $lockedIds = GoodsReceiptLine::query()
            ->whereIn('po_line_id', $poLineIds)
            ->distinct()
            ->pluck('po_line_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $lockedIds;
    }

    /**
     * @param  array<string, string>  $batchFreightShares
     */
    private function hasPositiveFreightPool(array $batchFreightShares): bool
    {
        $pool = '0.000000';
        foreach ($batchFreightShares as $share) {
            $pool = bcadd($pool, CurrencyScale::bcround((string) $share, self::COST_SCALE), self::COST_SCALE);
        }

        return bccomp($pool, '0', self::COST_SCALE) > 0;
    }

    /**
     * @param  numeric-string  $receivedQty
     * @param  numeric-string  $freeQty
     * @param  numeric-string  $landedUnitCost
     * @return numeric-string
     */
    private function effectiveUnitCost(string $receivedQty, string $freeQty, string $landedUnitCost): string
    {
        $totalQty = bcadd($receivedQty, $freeQty, self::QUANTITY_SCALE);

        if (bccomp($receivedQty, '0.0000', self::QUANTITY_SCALE) <= 0 || bccomp($totalQty, '0.0000', self::QUANTITY_SCALE) <= 0) {
            return '0.000000';
        }

        $paidValue = bcmul($receivedQty, $landedUnitCost, self::WORKING_SCALE);
        $effective = bcdiv($paidValue, $totalQty, self::WORKING_SCALE);

        return CurrencyScale::bcround($effective, self::COST_SCALE);
    }

    /**
     * @param  numeric-string  $receivedQty
     * @param  numeric-string  $baseUnitCost
     * @param  numeric-string  $batchFreightShare
     * @return numeric-string
     */
    private function landedUnitCostForReceipt(string $receivedQty, string $baseUnitCost, string $batchFreightShare): string
    {
        if (bccomp($receivedQty, '0.0000', self::QUANTITY_SCALE) <= 0) {
            return CurrencyScale::bcround($baseUnitCost, self::COST_SCALE);
        }

        $baseValue = bcmul($receivedQty, $baseUnitCost, self::WORKING_SCALE);
        $totalValue = bcadd($baseValue, $batchFreightShare, self::WORKING_SCALE);

        return CurrencyScale::bcround(bcdiv($totalValue, $receivedQty, self::WORKING_SCALE), self::COST_SCALE);
    }

    /**
     * Get receipt status for a purchase order.
     *
     * @return array{status: string, total_ordered: string, total_received: string, percentage: float, lines: array<int, array<string, mixed>>}
     */
    public function getReceiptStatus(Document $purchaseOrder): array
    {
        $totalOrdered = '0.00';
        $totalReceived = '0.00';
        $lineStatus = [];

        foreach ($purchaseOrder->lines as $line) {
            $qty = (string) $line->quantity;
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeQty = (string) ($line->free_quantity ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');
            $remaining = bcsub($qty, $received, self::QUANTITY_SCALE);
            $freeRemaining = bcsub($freeQty, $freeReceived, self::QUANTITY_SCALE);

            $totalOrdered = bcadd($totalOrdered, $qty, self::QUANTITY_SCALE);
            $totalReceived = bcadd($totalReceived, $received, self::QUANTITY_SCALE);

            $lineStatus[] = [
                'line_id' => $line->id,
                'product_name' => $line->product->name ?? $line->description,
                'quantity_ordered' => $qty,
                'quantity_received' => $received,
                'quantity_remaining' => $remaining,
                'free_quantity_ordered' => $freeQty,
                'free_quantity_received' => $freeReceived,
                'free_quantity_remaining' => $freeRemaining,
                'is_complete' => bccomp($remaining, '0.00', self::QUANTITY_SCALE) <= 0 && bccomp($freeRemaining, '0.00', self::QUANTITY_SCALE) <= 0,
            ];
        }

        $percentage = (float) $totalOrdered > 0
            ? round(((float) $totalReceived / (float) $totalOrdered) * 100, 2)
            : 0.0;

        $status = match (true) {
            bccomp($totalReceived, '0.00', self::QUANTITY_SCALE) <= 0 => 'not_received',
            bccomp($totalReceived, $totalOrdered, self::QUANTITY_SCALE) >= 0 => 'fully_received',
            default => 'partially_received',
        };

        return [
            'status' => $status,
            'total_ordered' => $totalOrdered,
            'total_received' => $totalReceived,
            'percentage' => $percentage,
            'lines' => $lineStatus,
        ];
    }

    /**
     * Check if a purchase order is fully received.
     */
    public function isFullyReceived(Document $purchaseOrder): bool
    {
        foreach ($purchaseOrder->lines as $line) {
            $qty = (string) $line->quantity;
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeQty = (string) ($line->free_quantity ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');

            if (bccomp($received, $qty, self::QUANTITY_SCALE) < 0 || bccomp($freeReceived, $freeQty, self::QUANTITY_SCALE) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any goods have been received for this PO.
     */
    public function hasReceivedGoods(Document $purchaseOrder): bool
    {
        foreach ($purchaseOrder->lines as $line) {
            $received = (string) ($line->quantity_received ?? '0.00');
            $freeReceived = (string) ($line->free_quantity_received ?? '0.00');

            if (bccomp($received, '0.00', self::QUANTITY_SCALE) > 0 || bccomp($freeReceived, '0.00', self::QUANTITY_SCALE) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the default location for a company.
     *
     * @throws \RuntimeException If no default location is configured
     */
    private function getDefaultLocation(Document $document): Location
    {
        $location = $document->company->locations()
            ->where('is_default', true)
            ->first();

        if ($location === null) {
            // Fall back to any location
            $location = $document->company->locations()->first();
        }

        if ($location === null) {
            throw new \RuntimeException('No location configured for company. Please set up at least one location.');
        }

        return $location;
    }

    private function resolveDestinationLocation(Document $purchaseOrder, ?string $destinationLocationId): Location
    {
        if ($destinationLocationId !== null) {
            $location = Location::query()
                ->where('company_id', $purchaseOrder->company_id)
                ->where('is_active', true)
                ->find($destinationLocationId);
            if ($location === null) {
                throw new \DomainException('Receiving destination is not available for this company.');
            }

            return $location;
        }

        return $purchaseOrder->location ?? $this->getDefaultLocation($purchaseOrder);
    }
}
