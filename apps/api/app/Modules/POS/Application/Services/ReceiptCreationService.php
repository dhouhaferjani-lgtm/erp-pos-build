<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\DiscountCalculationService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\CartItemContext;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for creating POS receipts with stock decrement and fiscal hashing.
 *
 * Handles the full receipt creation flow:
 * 1. Validate active shift on terminal
 * 2. Calculate line totals and VAT
 * 3. Generate receipt number from terminal sequence
 * 4. Create Receipt with lines and VAT details
 * 5. Decrement stock with pessimistic locking
 * 6. Create StockMovement audit records
 * 7. Compute fiscal hash chain
 */
final class ReceiptCreationService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly FEFOInventoryService $fefoService,
        private readonly BatchStockService $batchStockService,
        private readonly DiscountCalculationService $discountCalculationService,
        private readonly DiscountOrchestratorService $discountOrchestrator,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Create a new POS receipt.
     *
     * @param  string  $terminalId  Terminal UUID or code
     * @param  array<int, array{product_id?: string, composite_item_id?: string, quantity: string, unit_price: string, modifiers?: array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>, discount_amount?: string, discount_type?: string, discount_percent?: string, discount_reason?: string, discount_authorized_by?: string}>  $lines
     * @param  string|null  $customerId  Optional partner ID
     * @param  string|null  $contactId  Optional contact ID
     * @param  string|null  $notes  Optional notes
     * @param  string|null  $transactionDiscountAmount  Optional transaction-level discount (fixed amount)
     * @param  string|null  $transactionDiscountReason  Optional reason for transaction discount
     * @param  string|null  $couponCode  Optional coupon code to apply
     * @param  string|null  $loyaltyDiscountAmount  Optional loyalty reward discount
     * @param  string|null  $loyaltyRewardId  Optional loyalty reward reference
     * @param  ConsumptionMode|null  $consumptionMode  Optional consumption mode (F&B)
     * @return Receipt The created receipt with relationships loaded
     *
     * @throws \RuntimeException If no active shift or insufficient stock
     * @throws \InvalidArgumentException If lines are empty or products not found
     * @throws DiscountNotAllowedException If discount is not permitted
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    public function createReceipt(
        string $terminalId,
        array $lines,
        ?string $customerId = null,
        ?string $contactId = null,
        ?string $notes = null,
        ?string $transactionDiscountAmount = null,
        ?string $transactionDiscountReason = null,
        ?string $couponCode = null,
        ?string $loyaltyDiscountAmount = null,
        ?string $loyaltyRewardId = null,
        ?ConsumptionMode $consumptionMode = null,
    ): Receipt {
        if (count($lines) === 0) {
            throw new \InvalidArgumentException('At least one line item is required');
        }

        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($terminalId, $lines, $customerId, $contactId, $notes, $companyId, $transactionDiscountAmount, $transactionDiscountReason, $couponCode, $loyaltyDiscountAmount, $loyaltyRewardId, $consumptionMode): Receipt {
            // 1. Lock and load terminal
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $terminalId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $terminal->isActive()) {
                throw new \RuntimeException('Terminal is not active');
            }

            // 2. Verify active shift (eager-load cashier to avoid lazy load under lock)
            $shift = Shift::with('cashier')
                ->where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->first();

            if ($shift === null) {
                throw new \RuntimeException('No active shift on this terminal. Open a shift first.');
            }

            // Load company for currency
            /** @var Company $company */
            $company = $terminal->company ?? Company::findOrFail($terminal->company_id);

            // 3. Load and validate sellable items (products and/or composite items)
            $productIds = array_values(array_filter(array_column($lines, 'product_id')));
            $compositeItemIds = array_values(array_filter(array_column($lines, 'composite_item_id')));

            $products = count($productIds) > 0
                ? Product::with('unitOfMeasure')->whereIn('id', $productIds)->get()->keyBy('id')
                : collect();

            $compositeItems = count($compositeItemIds) > 0
                ? CompositeItem::with(['unitOfMeasure', 'modifierGroups.modifiers' => function ($query): void {
                    $query->where('is_active', true);
                }])->whereIn('id', $compositeItemIds)->get()->keyBy('id')
                : collect();

            foreach ($lines as $line) {
                if (! empty($line['product_id']) && ! $products->has($line['product_id'])) {
                    throw new \InvalidArgumentException("Product not found: {$line['product_id']}");
                }
                if (! empty($line['composite_item_id']) && ! $compositeItems->has($line['composite_item_id'])) {
                    throw new \InvalidArgumentException("Composite item not found: {$line['composite_item_id']}");
                }
            }

            // 4. Calculate line totals and aggregate VAT
            /** @var User $cashier */
            $cashier = $shift->cashier;
            $receiptLines = [];
            $vatAggregates = []; // keyed by tax_rate
            $subtotal = '0.00';
            $totalTax = '0.00';

            foreach ($lines as $index => $lineData) {
                $isCompositeItem = ! empty($lineData['composite_item_id']);
                $compositeItem = null;

                if ($isCompositeItem) {
                    /** @var CompositeItem $compositeItem */
                    $compositeItem = $compositeItems->get($lineData['composite_item_id']);
                    $sellableName = $compositeItem->getSellableName();
                    $sellableCode = $compositeItem->code;
                    $sellableUnit = $compositeItem->getSellableUnit() ?? 'pc';
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) ($compositeItem->tax_rate ?? '0.00');
                    $sellableDescription = null;
                } else {
                    $productId = $lineData['product_id'] ?? '';
                    /** @var Product $product */
                    $product = $products->get($productId);
                    $sellableName = $product->name;
                    $sellableCode = $product->sku ?? $product->barcode ?? '';
                    $sellableUnit = $product->unitOfMeasure->symbol ?? $product->unit ?? 'pc';
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) ($product->tax_rate ?? '0.00');
                    $sellableDescription = $product->description;
                }

                /** @var numeric-string $quantity */
                $quantity = (string) $lineData['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineData['unit_price'];

                // Process modifiers for composite item lines
                $modifiersSnapshot = null;
                if ($isCompositeItem && ! empty($lineData['modifiers']) && $compositeItem !== null) {
                    $modifiersSnapshot = $this->processModifiers(
                        $compositeItem,
                        $lineData['modifiers'],
                    );
                }

                // Calculate gross line total before discount
                $grossLineTotal = bcmul($quantity, $unitPrice, $this->scale());

                // Resolve line discount amount from type/percent/amount
                $discountAmount = $this->resolveLineDiscountAmount(
                    $lineData,
                    $grossLineTotal,
                    $terminal,
                    $cashier,
                );

                // line_total = (qty * unit_price) - discount
                $lineTotal = bcsub($grossLineTotal, $discountAmount, $this->scale());

                // Calculate tax: net = lineTotal / (1 + taxRate/100), tax = lineTotal - net
                $taxRateDecimal = bcdiv($taxRate, '100', 6);
                $divisor = bcadd('1', $taxRateDecimal, 6);
                $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());

                $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                $totalTax = bcadd($totalTax, $taxAmount, $this->scale());

                $receiptLines[] = [
                    'line_number' => $index + 1,
                    'product_id' => $compositeItem !== null ? null : ($lineData['product_id'] ?? null),
                    'composite_item_id' => $compositeItem !== null ? ($lineData['composite_item_id'] ?? null) : null,
                    'product_code' => $sellableCode,
                    'product_name' => $sellableName,
                    'product_description' => $sellableDescription,
                    'quantity' => $quantity,
                    'unit' => $sellableUnit,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'modifiers' => $modifiersSnapshot,
                    'discount_amount' => $discountAmount,
                    'discount_reason' => $lineData['discount_reason'] ?? null,
                ];

                // Aggregate VAT by rate
                $rateKey = $taxRate;
                if (! isset($vatAggregates[$rateKey])) {
                    $vatAggregates[$rateKey] = [
                        'tax_rate' => $taxRate,
                        'net_amount' => '0.00',
                        'vat_amount' => '0.00',
                        'gross_amount' => '0.00',
                    ];
                }
                $vatAggregates[$rateKey]['net_amount'] = bcadd($vatAggregates[$rateKey]['net_amount'], $netAmount, $this->scale());
                $vatAggregates[$rateKey]['vat_amount'] = bcadd($vatAggregates[$rateKey]['vat_amount'], $taxAmount, $this->scale());
                $vatAggregates[$rateKey]['gross_amount'] = bcadd($vatAggregates[$rateKey]['gross_amount'], $lineTotal, $this->scale());
            }

            // 4a-fix. Recalculate VAT aggregates from aggregated net_amount
            // to satisfy DB constraint: vat_amount = round(net_amount * tax_rate / 100, 2)
            // Per-line rounding then summing causes drift vs computing from aggregate.
            /** @var numeric-string $totalTax */
            $totalTax = '0.00';
            foreach ($vatAggregates as &$vatData) {
                $vatData['vat_amount'] = $this->roundVat($vatData['net_amount'], $vatData['tax_rate']);
                /** @var numeric-string $vatNetAmount */
                $vatNetAmount = $vatData['net_amount'];
                /** @var numeric-string $vatAmount */
                $vatAmount = $vatData['vat_amount'];
                $vatData['gross_amount'] = bcadd($vatNetAmount, $vatAmount, $this->scale());
                $totalTax = bcadd($totalTax, $vatAmount, $this->scale());
            }
            unset($vatData);

            // 4b. Validate and apply transaction-level discount
            $validatedTransactionDiscount = '0.00';
            $discountReason = null;
            $discountAuthorizedBy = null;

            /** @var numeric-string $txDiscountStr */
            $txDiscountStr = $transactionDiscountAmount ?? '0.00';

            if ($transactionDiscountAmount !== null && bccomp($txDiscountStr, '0', $this->scale()) > 0) {
                // Subtotal for validation is the sum of all line totals (gross before tax split)
                /** @var numeric-string $grossTotal */
                $grossTotal = bcadd($subtotal, $totalTax, $this->scale());

                /** @var numeric-string $numericDiscountAmount */
                $numericDiscountAmount = bcadd($txDiscountStr, '0', $this->scale());

                $this->discountCalculationService->validateTransactionDiscount(
                    $terminal,
                    $cashier,
                    $grossTotal,
                    $numericDiscountAmount,
                    $transactionDiscountReason,
                );

                $validatedTransactionDiscount = $numericDiscountAmount;
                $discountReason = $transactionDiscountReason;
                $effectiveLimit = $this->discountCalculationService->getEffectiveDiscountLimit($terminal, $cashier);
                $discountAuthorizedBy = number_format($effectiveLimit['limit'], 2, '.', '');
            }

            $total = bcsub(bcadd($subtotal, $totalTax, $this->scale()), $validatedTransactionDiscount, $this->scale());

            // 4c. Resolve full discount breakdown via orchestrator (audit-only JSONB)
            $discountBreakdownData = null;
            try {
                /** @var numeric-string $grossTotal */
                $grossTotal = bcadd($subtotal, $totalTax, $this->scale());

                $cartItems = [];
                foreach ($lines as $lineData) {
                    $isComposite = ! empty($lineData['composite_item_id']);
                    /** @var numeric-string $itemUnitPrice */
                    $itemUnitPrice = (string) $lineData['unit_price'];
                    /** @var numeric-string $itemQty */
                    $itemQty = (string) $lineData['quantity'];
                    /** @var numeric-string $itemLineTotal */
                    $itemLineTotal = bcmul($itemQty, $itemUnitPrice, $this->scale());

                    $itemProductId = $lineData['product_id'] ?? '';
                    $itemCompositeId = $lineData['composite_item_id'] ?? '';
                    $itemId = $isComposite ? $itemCompositeId : $itemProductId;
                    $categoryId = null;
                    if (! $isComposite && $products->has($itemProductId)) {
                        $categoryId = $products->get($itemProductId)->category_id ?? null;
                    } elseif ($isComposite && $compositeItems->has($itemCompositeId)) {
                        $categoryId = $compositeItems->get($itemCompositeId)->category_id ?? null;
                    }

                    $cartItems[] = new CartItemContext(
                        productId: $itemId,
                        categoryId: $categoryId,
                        quantity: (int) $lineData['quantity'],
                        unitPrice: $itemUnitPrice,
                        lineTotal: $itemLineTotal,
                    );
                }

                $cart = new CartContext(
                    tenantId: $terminal->tenant_id,
                    companyId: $companyId,
                    items: $cartItems,
                    subtotal: $grossTotal,
                    appliedAt: Carbon::now()->toIso8601String(),
                );

                $breakdown = $this->discountOrchestrator->resolve(
                    cart: $cart,
                    manualDiscountAmount: $validatedTransactionDiscount !== '0.00' ? $validatedTransactionDiscount : null,
                    manualDiscountReason: $discountReason,
                    couponCode: $couponCode,
                    customerId: $customerId,
                    loyaltyDiscountAmount: $loyaltyDiscountAmount !== null ? number_format((float) $loyaltyDiscountAmount, $this->scale(), '.', '') : null,
                    loyaltyRewardId: $loyaltyRewardId,
                );

                $discountBreakdownData = $breakdown->toArray();
            } catch (\Throwable $e) {
                // Orchestrator failure must not block receipt creation
                Log::warning('Discount orchestrator failed during receipt creation', [
                    'error' => $e->getMessage(),
                    'terminal_id' => $terminalId,
                ]);
            }

            // 5. Generate receipt number and sequence
            $currentYear = (int) now()->format('Y');
            if ($terminal->needsSequenceReset()) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
            }

            $sequence = $terminal->current_sequence;
            $receiptNumber = $this->generateReceiptNumber($terminal, $currentYear, $sequence);

            // 6. Calculate VAT and payment hashes (payment hash is empty initially)
            $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
            $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

            // 7. Create receipt with previous_hash set immediately
            $now = Carbon::now();
            $previousHash = $terminal->last_hash;
            $currency = $company->currency ?? 'TND';

            // 6a. Resolve customer info before receipt creation (must be set at INSERT time,
            // not via UPDATE, because the immutability trigger blocks all updates except void)
            $customerName = null;
            $customerIdentifier = null;
            $partnerId = null;
            $resolvedContactId = null;

            if ($contactId !== null) {
                $contact = \App\Modules\Contact\Domain\Contact::find($contactId);
                if ($contact !== null) {
                    $customerName = $contact->full_name;
                    $customerIdentifier = $contact->phone ?? $contact->email ?? null;
                    $resolvedContactId = $contact->id;

                    // If contact is linked to a party, auto-set partner_id
                    $primaryParty = $contact->parties()->wherePivot('is_primary', true)->first();
                    if ($primaryParty !== null) {
                        $partnerId = $primaryParty->id;
                    }
                }
            }

            if ($customerName === null && $customerId !== null) {
                $partner = \App\Modules\Partner\Domain\Partner::find($customerId);
                if ($partner !== null) {
                    $customerName = $partner->name;
                    $customerIdentifier = $partner->phone ?? $partner->email ?? null;
                    $partnerId = $partner->id;
                }
            }

            // 7a. Build receipt in memory to calculate fiscal hash before INSERT
            $receiptId = Str::uuid()->toString();
            /** @var Receipt $receipt */
            $receipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $receiptNumber,
                'receipt_type' => \App\Modules\POS\Domain\Enums\ReceiptType::Sale,
                'chain_sequence' => $sequence,
                'receipt_year' => $currentYear,
                'previous_hash' => $previousHash,
                'posted_at' => $now,
                'cashier_id' => $shift->cashier_id,
                'cashier_name' => $cashier->name ?? 'Unknown',
                'subtotal' => $subtotal,
                'tax_amount' => $totalTax,
                'discount_amount' => $validatedTransactionDiscount,
                'discount_reason' => $discountReason,
                'discount_authorized_by' => $discountAuthorizedBy,
                'total' => $total,
                'currency' => $currency,
                'customer_name' => $customerName,
                'customer_identifier' => $customerIdentifier,
                'partner_id' => $partnerId,
                'contact_id' => $resolvedContactId,
                'is_voided' => false,
                'vat_breakdown_hash' => $vatHash,
                'payment_methods_hash' => $paymentHash,
                'consumption_mode' => $consumptionMode,
                'discount_breakdown' => $discountBreakdownData,
                'notes' => $notes,
            ]);

            // 7b. Calculate fiscal hash before saving (fiscal_hash is NOT NULL)
            $receipt->id = $receiptId;
            $receipt->setRelation('terminal', $terminal);
            $fiscalHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
            $receipt->fiscal_hash = $fiscalHash;
            $receipt->save();

            // 8. Create receipt lines
            foreach ($receiptLines as $lineData) {
                ReceiptLine::create(array_merge($lineData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                ]));
            }

            // 9. Create VAT details
            foreach ($vatAggregates as $vatData) {
                ReceiptVatDetail::create(array_merge($vatData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                ]));
            }

            // Update terminal sequence and hash chain
            $terminal->current_sequence = $sequence + 1;
            $terminal->last_hash = $fiscalHash;
            $terminal->save();

            // 11. Decrement stock for each line (with pessimistic locking)
            // Also collect receipt line IDs for batch allocation
            $createdReceiptLines = $receipt->lines()->orderBy('line_number')->get();

            foreach ($receiptLines as $index => $lineData) {
                $receiptLineModel = $createdReceiptLines[$index] ?? null;

                // Only decrement stock for product lines (composite items handle their own inventory)
                if ($lineData['product_id'] !== null) {
                    $this->decrementStock(
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        locationId: $terminal->location_id,
                        productId: $lineData['product_id'],
                        quantity: $lineData['quantity'],
                        receiptId: $receipt->id,
                        cashierId: $shift->cashier_id,
                    );

                    // Allocate batches using FEFO for batch-tracked products
                    if ($receiptLineModel !== null && $this->fefoService->productRequiresBatchTracking($lineData['product_id'])) {
                        $this->allocateBatches(
                            tenantId: $terminal->tenant_id,
                            locationId: $terminal->location_id,
                            productId: $lineData['product_id'],
                            quantity: $lineData['quantity'],
                            receiptId: $receipt->id,
                            receiptLineId: $receiptLineModel->id,
                        );
                    }
                }
            }

            // Return fresh receipt with relations
            /** @var Receipt $freshReceipt */
            $freshReceipt = $receipt->fresh([
                'lines.product',
                'vatDetails',
                'terminal',
                'cashier',
            ]);

            return $freshReceipt;
        });
    }

    /**
     * Process and validate modifiers for a composite item line.
     *
     * Validates that selected modifiers belong to the item's assigned groups,
     * checks selection constraints (min/max), and builds the snapshot array.
     *
     * @param  CompositeItem  $compositeItem  The composite item with loaded modifierGroups.modifiers
     * @param  array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>  $requestedModifiers
     * @return array<int, array{modifier_id: string, modifier_group_id: string, name: string, group_name: string, price_adjustment: string}>
     *
     * @throws \InvalidArgumentException If modifier validation fails
     */
    private function processModifiers(CompositeItem $compositeItem, array $requestedModifiers): array
    {
        // Index the item's modifier groups for quick lookup
        $groupsById = $compositeItem->modifierGroups->keyBy('id');

        // Group requested modifiers by group_id for constraint validation
        /** @var array<string, array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>> $byGroup */
        $byGroup = [];
        foreach ($requestedModifiers as $mod) {
            $byGroup[$mod['modifier_group_id']][] = $mod;
        }

        // Validate each requested modifier belongs to an assigned group
        foreach ($requestedModifiers as $mod) {
            if (! $groupsById->has($mod['modifier_group_id'])) {
                throw new \InvalidArgumentException(
                    "Modifier group '{$mod['modifier_group_id']}' is not assigned to composite item '{$compositeItem->id}'"
                );
            }
        }

        // Validate selection constraints per group
        foreach ($groupsById as $groupId => $group) {
            /** @var \App\Modules\Catalog\Domain\Entities\ModifierGroup $group */
            $selectedInGroup = $byGroup[$groupId] ?? [];
            $count = count($selectedInGroup);

            if ($group->is_required && $count < $group->min_selections) {
                throw new \InvalidArgumentException(
                    "Modifier group '{$group->name}' requires at least {$group->min_selections} selection(s), got {$count}"
                );
            }

            if ($count > 0 && $count > $group->max_selections) {
                throw new \InvalidArgumentException(
                    "Modifier group '{$group->name}' allows at most {$group->max_selections} selection(s), got {$count}"
                );
            }

            // Validate each modifier exists in this group
            $groupModifierIds = $group->modifiers->pluck('id')->all();
            foreach ($selectedInGroup as $mod) {
                if (! in_array($mod['modifier_id'], $groupModifierIds, true)) {
                    throw new \InvalidArgumentException(
                        "Modifier '{$mod['modifier_id']}' does not belong to group '{$group->name}'"
                    );
                }
            }
        }

        // Build snapshot array with denormalized names
        $snapshot = [];
        foreach ($requestedModifiers as $mod) {
            /** @var \App\Modules\Catalog\Domain\Entities\ModifierGroup $group */
            $group = $groupsById->get($mod['modifier_group_id']);
            /** @var \App\Modules\Catalog\Domain\Entities\Modifier|null $modifier */
            $modifier = $group->modifiers->firstWhere('id', $mod['modifier_id']);

            $snapshot[] = [
                'modifier_id' => $mod['modifier_id'],
                'modifier_group_id' => $mod['modifier_group_id'],
                'name' => $modifier->name ?? 'Unknown',
                'group_name' => $group->name,
                'price_adjustment' => (string) $mod['price_adjustment'],
            ];
        }

        return $snapshot;
    }

    /**
     * Generate receipt number using terminal code and sequence.
     *
     * Format: {terminal_code}-{year}-{sequence}
     * Example: POS01-2026-00000001
     */
    private function generateReceiptNumber(Terminal $terminal, int $year, int $sequence): string
    {
        $terminalCode = $terminal->code;
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return "{$terminalCode}-{$year}-{$sequenceStr}";
    }

    /**
     * Decrement stock for a product at a location, with pessimistic lock.
     *
     * Creates a StockMovement audit record.
     *
     * @throws \RuntimeException If insufficient stock
     */
    private function decrementStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $cashierId,
    ): void {
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            // No stock record — skip stock decrement for products without inventory tracking
            return;
        }

        /** @var numeric-string $available */
        $available = $stockLevel->getAvailableQuantity();
        /** @var numeric-string $quantity */
        if (bccomp($available, $quantity, 4) < 0) {
            $product = Product::find($productId);
            $productName = $product->name ?? $productId;
            throw new \RuntimeException(
                "Insufficient stock for '{$productName}'. Available: {$available}, Requested: {$quantity}"
            );
        }

        $quantityBefore = $stockLevel->quantity;
        /** @var numeric-string $stockQty */
        $stockQty = $stockLevel->quantity;
        $quantityAfter = bcsub($stockQty, $quantity, 2);

        // Update stock level
        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        // Create stock movement audit record
        StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::POSSale,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference' => 'POS Sale',
            'reference_type' => 'pos_receipt',
            'reference_id' => $receiptId,
            'notes' => "Stock issued via POS sale (receipt: {$receiptId})",
            'user_id' => $cashierId,
            'is_historical' => false,
        ]);
    }

    /**
     * Round VAT amount to match PostgreSQL: round((net_amount * tax_rate / 100)::numeric, 2).
     *
     * Uses PHP round() which matches PostgreSQL round() (half away from zero).
     */
    private function roundVat(string $netAmount, string $taxRate): string
    {
        $raw = (float) $netAmount * (float) $taxRate / 100.0;

        return number_format(round($raw, $this->scale()), $this->scale(), '.', '');
    }

    /**
     * Resolve line discount amount from request data.
     *
     * Handles both percentage-based and fixed-amount discounts.
     * Validates against terminal/cashier limits when discount > 0.
     *
     * @param  array{product_id?: string, composite_item_id?: string, quantity: string, unit_price: string, discount_amount?: string, discount_type?: string, discount_percent?: string, discount_reason?: string}  $lineData
     * @param  numeric-string  $grossLineTotal  The gross line total before discount
     * @param  Terminal  $terminal  The terminal for limit checks
     * @param  User  $cashier  The cashier for permission/limit checks
     * @return numeric-string The calculated discount amount
     *
     * @throws DiscountNotAllowedException If line discounts not allowed
     * @throws DiscountExceedsLimitException If discount exceeds limits
     */
    private function resolveLineDiscountAmount(
        array $lineData,
        string $grossLineTotal,
        Terminal $terminal,
        User $cashier,
    ): string {
        $discountType = $lineData['discount_type'] ?? null;
        $discountPercent = isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null;
        /** @var numeric-string|null $discountAmount */
        $discountAmount = isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null;
        $discountReason = $lineData['discount_reason'] ?? null;

        // If percentage-based discount, calculate the amount
        if ($discountType === 'percentage' && $discountPercent !== null && (float) $discountPercent > 0) {
            $this->discountCalculationService->validateLineDiscount(
                $terminal,
                $cashier,
                (float) $discountPercent,
                $discountReason,
            );

            return $this->discountCalculationService->calculateLineDiscountAmount(
                $grossLineTotal,
                (float) $discountPercent,
            );
        }

        // If fixed-amount discount
        if ($discountAmount !== null && bccomp((string) $discountAmount, '0', $this->scale()) > 0) {
            /** @var numeric-string $numericDiscount */
            $numericDiscount = bcadd($discountAmount, '0', $this->scale());

            // Derive percent from amount for validation
            if (bccomp($grossLineTotal, '0', $this->scale()) > 0) {
                /** @var numeric-string $derivedPercent */
                $derivedPercent = bcdiv(
                    bcmul($numericDiscount, '100', 10),
                    $grossLineTotal,
                    2,
                );

                $this->discountCalculationService->validateLineDiscount(
                    $terminal,
                    $cashier,
                    (float) $derivedPercent,
                    $discountReason,
                );
            }

            return $this->discountCalculationService->calculateFixedDiscountAmount(
                $grossLineTotal,
                $numericDiscount,
            );
        }

        return '0.00';
    }

    /**
     * Allocate batches using FEFO for a POS receipt line.
     *
     * Creates ReceiptLineBatchAllocation records and deducts batch-level stock.
     * If FEFO cannot fully fulfill, logs a warning but does not block the sale
     * (aggregate stock check already passed).
     */
    private function allocateBatches(
        string $tenantId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $receiptLineId,
    ): void {
        $result = $this->fefoService->suggestBatchesForSale(
            $productId,
            $locationId,
            (float) $quantity,
        );

        if ($result->hasShortfall()) {
            Log::warning('POS batch allocation shortfall - global stock passed but batch stock insufficient', [
                'product_id' => $productId,
                'location_id' => $locationId,
                'requested' => $quantity,
                'fulfilled' => $result->getSuggestedQuantity(),
                'shortfall' => $result->shortfall,
                'receipt_id' => $receiptId,
            ]);
        }

        foreach ($result->suggestions as $suggestion) {
            /** @var numeric-string $batchQty */
            $batchQty = (string) $suggestion->quantity;

            // Create allocation record (snapshot batch info for traceability)
            ReceiptLineBatchAllocation::create([
                'receipt_id' => $receiptId,
                'receipt_line_id' => $receiptLineId,
                'batch_id' => $suggestion->batch->id,
                'quantity' => $batchQty,
                'batch_number' => $suggestion->batch->batch_number,
                'expiry_date' => $suggestion->batch->expiry_date,
            ]);

            // Deduct batch-level stock
            $this->batchStockService->issueBatchStock(
                tenantId: $tenantId,
                batchId: (int) $suggestion->batch->id,
                locationId: $locationId,
                quantity: $batchQty,
            );
        }
    }
}
