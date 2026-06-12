<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Concerns\RoundsVat;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\ReceiptDrafted;
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
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for creating POS receipts with stock decrement.
 *
 * Handles the draft-creation phase of the receipt lifecycle:
 * 1. Validate active shift on terminal
 * 2. Calculate line totals and VAT
 * 3. Generate receipt number from terminal sequence (advances current_sequence)
 * 4. Create Receipt in pending_seal state (fiscal_hash = null, no chain advance)
 * 5. Decrement stock with pessimistic locking
 * 6. Create StockMovement audit records
 *
 * Fiscal sealing (hash computation and terminal last_hash advance) happens
 * separately in ReceiptFinalizationService::finalize() once payment is confirmed.
 * Training receipts are sealed inline at create time (they are not part of the chain).
 */
final class ReceiptCreationService
{
    use RoundsVat;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly FEFOInventoryService $fefoService,
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
     * @param  array<int, array{product_id?: string, variant_id?: string, composite_item_id?: string, quantity: string, unit_price: string, modifiers?: array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>, discount_amount?: string, discount_type?: string, discount_percent?: string, discount_reason?: string, discount_authorized_by?: string}>  $lines
     * @param  string|null  $customerId  Optional partner ID
     * @param  string|null  $contactId  Optional contact ID
     * @param  string|null  $notes  Optional notes
     * @param  string|null  $transactionDiscountAmount  Optional transaction-level discount (fixed amount)
     * @param  string|null  $transactionDiscountReason  Optional reason for transaction discount
     * @param  string|null  $couponCode  Optional coupon code to apply
     * @param  string|null  $loyaltyDiscountAmount  Optional loyalty reward discount
     * @param  string|null  $loyaltyRewardId  Optional loyalty reward reference
     * @param  ConsumptionMode|null  $consumptionMode  Optional consumption mode (F&B)
     * @param  string|null  $exchangeGroupId  Exchange group UUID to commit in both halves' v3 hash (Phase F)
     * @return Receipt The created receipt with relationships loaded
     *
     * @throws \RuntimeException If no active shift or insufficient stock
     * @throws \InvalidArgumentException If lines are empty or products not found
     * @throws DiscountNotAllowedException If discount is not permitted
     * @throws DiscountExceedsLimitException If discount exceeds limits
     *
     * @internal §14.3 chokepoint (Task 30). All call sites are catalogued
     *           in `apps/api/scripts/saleReceipt-chokepoint-manifest.json`:
     *           - `ReceiptController::store` (Task 29 §14.2: 410 Gone) — `live: true`, disposition (b)
     *           - `OrderToReceiptService::convertToReceipt` (Task 29 §14.2: 410 Gone) — `live: true`, disposition (b)
     *           - `ExchangeService::processExchange` — `live: false`, disposition (b)
     *           Adding a new caller without updating the manifest will fail
     *           the §14.3 CI gate.
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
        ?string $exchangeGroupId = null,
    ): Receipt {
        if (count($lines) === 0) {
            throw new \InvalidArgumentException('At least one line item is required');
        }

        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($terminalId, $lines, $customerId, $contactId, $notes, $companyId, $transactionDiscountAmount, $transactionDiscountReason, $couponCode, $loyaltyDiscountAmount, $loyaltyRewardId, $consumptionMode, $exchangeGroupId): Receipt {
            // 1. Lock and load terminal with location
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $terminalId)
                ->lockForUpdate()
                ->firstOrFail();

            $terminal->load('location:id,code,name');

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

            // Resolve the over-stock enforcement policy ONCE per receipt
            // (spec §4.2) and thread it into every stock decrement below —
            // including composite leaf deduction. Block throws; Warn/Off
            // log and proceed (negative stock allowed).
            $stockPolicy = $company->pos_stock_policy;

            // 3. Load and validate sellable items (products and/or composite items)
            $productIds = array_values(array_filter(array_column($lines, 'product_id')));
            $compositeItemIds = array_values(array_filter(array_column($lines, 'composite_item_id')));

            $products = count($productIds) > 0
                ? Product::with('unitOfMeasure')->whereIn('id', $productIds)->get()->keyBy('id')
                : collect();

            $compositeItems = count($compositeItemIds) > 0
                ? CompositeItem::with(['unitOfMeasure', 'activeRecipe.lines.product', 'activeRecipe.lines.compositeItemComponent', 'modifierGroups.modifiers' => function ($query): void {
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
            /** @var numeric-string $sumLineTotals Sum of gross line totals (TTC) — used for receipt total to avoid VAT rounding drift */
            $sumLineTotals = '0';

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
                $sumLineTotals = bcadd($sumLineTotals, $lineTotal, $this->scale());

                // Check if this is a fixed_bundle composite item with mixed VAT rates
                $isFixedBundle = $compositeItem !== null
                    && ($compositeItem->pricing_mode ?? null) === PricingMode::FixedBundle;

                $comboComponents = null;

                if ($isFixedBundle) {
                    /** @var CompositeItem $compositeItem (guaranteed non-null by $isFixedBundle check) */
                    // Decompose VAT across components with different rates
                    $vatDecomposition = $this->decomposeFixedBundleVat($compositeItem, $lineTotal);

                    if (count($vatDecomposition) > 0) {
                        /** @var numeric-string $lineNetAmount */
                        $lineNetAmount = '0';
                        /** @var numeric-string $lineTaxAmount */
                        $lineTaxAmount = '0';
                        $comboComponents = [];

                        foreach ($vatDecomposition as $decomp) {
                            /** @var numeric-string $decompNet */
                            $decompNet = $decomp['net_amount'];
                            /** @var numeric-string $decompTax */
                            $decompTax = $decomp['tax_amount'];
                            /** @var numeric-string $decompShare */
                            $decompShare = $decomp['share'];

                            $lineNetAmount = bcadd($lineNetAmount, $decompNet, $this->scale());
                            $lineTaxAmount = bcadd($lineTaxAmount, $decompTax, $this->scale());
                            $comboComponents[] = $decomp['name'];

                            // Aggregate VAT by rate
                            $rateKey = $decomp['tax_rate'];
                            $this->aggregateVat($vatAggregates, $rateKey, $decompNet, $decompTax, $decompShare);
                        }

                        $netAmount = $lineNetAmount;
                        $taxAmount = $lineTaxAmount;
                        $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                        $totalTax = bcadd($totalTax, $taxAmount, $this->scale());
                    } else {
                        // Fallback: use composite item's own tax rate
                        $taxRateDecimal = bcdiv($taxRate, '100', 6);
                        $divisor = bcadd('1', $taxRateDecimal, 6);
                        $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                        $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());
                        $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                        $totalTax = bcadd($totalTax, $taxAmount, $this->scale());

                        $this->aggregateVat($vatAggregates, $taxRate, $netAmount, $taxAmount, $lineTotal);
                    }
                } else {
                    // Standard VAT calculation
                    $taxRateDecimal = bcdiv($taxRate, '100', 6);
                    $divisor = bcadd('1', $taxRateDecimal, 6);
                    $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                    $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());

                    $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                    $totalTax = bcadd($totalTax, $taxAmount, $this->scale());

                    $this->aggregateVat($vatAggregates, $taxRate, $netAmount, $taxAmount, $lineTotal);
                }

                // Capture unit cost for margin analytics
                $unitCost = null;
                if ($compositeItem !== null) {
                    $unitCost = $compositeItem->getEffectiveCost();
                } elseif (isset($lineData['product_id'])) {
                    /** @var Product|null $lineProduct */
                    $lineProduct = $products->get($lineData['product_id']);
                    $unitCost = $lineProduct?->cost_price;
                }

                $receiptLines[] = [
                    'line_number' => $index + 1,
                    'product_id' => $compositeItem !== null ? null : ($lineData['product_id'] ?? null),
                    'variant_id' => $compositeItem !== null ? null : ($lineData['variant_id'] ?? null),
                    'composite_item_id' => $compositeItem !== null ? ($lineData['composite_item_id'] ?? null) : null,
                    'product_code' => $sellableCode,
                    'product_name' => $sellableName,
                    'product_description' => $sellableDescription,
                    'quantity' => $quantity,
                    'unit' => $sellableUnit,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'modifiers' => $modifiersSnapshot,
                    'discount_amount' => $discountAmount,
                    'discount_reason' => $lineData['discount_reason'] ?? null,
                    'combo_components' => $comboComponents,
                ];
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

            // 4b. Validate manual transaction-level discount
            $validatedTransactionDiscount = '0.00';
            $discountReason = null;
            $discountAuthorizedBy = null;

            /** @var numeric-string $txDiscountStr */
            $txDiscountStr = $transactionDiscountAmount ?? '0.00';

            if ($transactionDiscountAmount !== null && bccomp($txDiscountStr, '0', $this->scale()) > 0) {
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
                $discountAuthorizedBy = CurrencyScale::bcformat($effectiveLimit['limit'], 2);
            }

            // 4c. Resolve full discount breakdown via orchestrator (promotions + manual + coupon + loyalty)
            $discountBreakdownData = null;
            /** @var numeric-string $effectiveTransactionDiscount */
            $effectiveTransactionDiscount = $validatedTransactionDiscount;

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
                    loyaltyDiscountAmount: $loyaltyDiscountAmount !== null ? CurrencyScale::bcformat($loyaltyDiscountAmount, $this->scale()) : null,
                    loyaltyRewardId: $loyaltyRewardId,
                );

                $discountBreakdownData = $breakdown->toArray();

                // Apply promotion line discounts to receipt lines
                $this->applyPromotionLineDiscounts(
                    $receiptLines,
                    $breakdown->lineDiscounts,
                    $subtotal,
                    $totalTax,
                    $vatAggregates,
                );

                // Recompute sumLineTotals from adjusted lines after promotion discounts
                if (count($breakdown->lineDiscounts) > 0) {
                    $sumLineTotals = '0';
                    foreach ($receiptLines as $rl) {
                        $sumLineTotals = bcadd($sumLineTotals, (string) $rl['line_total'], $this->scale());
                    }
                }

                // Use orchestrator's resolved transaction discount (includes manual + promo stacking)
                $effectiveTransactionDiscount = $breakdown->totalTransactionDiscount;
            } catch (\Throwable $e) {
                // Orchestrator failure: fall back to manual-only discount, no line adjustments
                Log::warning('Discount orchestrator failed during receipt creation', [
                    'error' => $e->getMessage(),
                    'terminal_id' => $terminalId,
                ]);
            }

            // Total = sum of line totals (TTC) - transaction discount
            // Uses $sumLineTotals (gross) instead of $subtotal + recalculated $totalTax
            // to avoid VAT recalculation rounding drift (e.g., 5.000 TND becoming 4.999).
            // Then derive $totalTax from the difference to maintain the accounting identity:
            // subtotal + tax_amount - discount = total
            $total = bcsub($sumLineTotals, $effectiveTransactionDiscount, $this->scale());
            $totalTax = bcsub($sumLineTotals, $subtotal, $this->scale());

            // 5. Generate receipt number and sequence
            $isTraining = $terminal->is_training_mode;
            $currentYear = (int) now()->format('Y');
            if ($terminal->needsSequenceReset()) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
            }

            $sequence = $terminal->current_sequence;
            $receiptNumber = $isTraining
                ? $this->generateTrainingReceiptNumber($terminal, $currentYear, $sequence)
                : $this->generateReceiptNumber($terminal, $currentYear, $sequence);

            // 6. Pre-compute VAT and payment hashes stored on the receipt row.
            // These are used as inputs to the fiscal hash computed later in
            // ReceiptFinalizationService::finalize(). The payment hash is seeded
            // with an empty payments list at create time; it will be correct once
            // payments are recorded via ReceiptPaymentService.
            $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
            $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

            // 7. Build receipt in draft state.
            // Non-training receipts are created as pending_seal: fiscal_hash, previous_hash,
            // and chain_sequence are null. The terminal hash chain is NOT advanced here.
            // ReceiptFinalizationService::finalize() seals the receipt and advances the chain.
            // Training receipts are sealed inline (they are excluded from the fiscal chain).
            $now = Carbon::now();
            $currency = $company->currency ?? 'TND';

            // 6a. Resolve customer info before receipt creation (must be set at INSERT time,
            // not via UPDATE, because the immutability trigger blocks all updates except void)
            $customerName = null;
            $customerIdentifier = null;
            $partnerId = null;
            $resolvedContactId = null;

            // api.pos-stabilization.022 — scope Contact::find by caller tenant + company.
            // api.pos-stabilization.023 — scope Partner::find similarly.
            // Cross-tenant ids resolve to null; the receipt persists without
            // the foreign customer name/contact reference.
            $callerTenantId = $this->companyContext->requireCompany()->tenant_id;
            if ($contactId !== null) {
                $contact = Contact::query()
                    ->where('tenant_id', $callerTenantId)
                    ->where('company_id', $companyId)
                    ->find($contactId);
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
                $partner = Partner::query()
                    ->where('tenant_id', $callerTenantId)
                    ->where('company_id', $companyId)
                    ->find($customerId);
                if ($partner !== null) {
                    $customerName = $partner->name;
                    $customerIdentifier = $partner->phone ?? $partner->email ?? null;
                    $partnerId = $partner->id;
                }
            }

            // 7a. Build and persist receipt.
            // Non-training receipts start as pending_seal (fiscal_hash, previous_hash,
            // and chain_sequence remain null until ReceiptFinalizationService::finalize()).
            // Training receipts are sealed inline with a placeholder hash because they
            // are never finalized and are excluded from the fiscal hash chain.
            // chain_sequence stays null for training: the pos_receipts_sequence PG
            // CHECK constraint rejects 0 (`chain_sequence IS NULL OR chain_sequence > 0`).
            // Mirrors the offline-sync path closed by PR #103.
            $receiptId = Str::uuid()->toString();

            $fiscalStatus = $isTraining ? FiscalStatus::Fiscalized : FiscalStatus::PendingSeal;
            $trainingFiscalHash = $isTraining ? hash('sha256', 'TRAINING-'.$receiptId) : null;
            $trainingChainSequence = null;

            /** @var Receipt $receipt */
            $receipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $receiptNumber,
                'receipt_type' => ReceiptType::Sale,
                'fiscal_status' => $fiscalStatus,
                'chain_sequence' => $trainingChainSequence,
                'receipt_year' => $currentYear,
                'fiscal_hash' => $trainingFiscalHash,
                'previous_hash' => null,
                'posted_at' => $now,
                'cashier_id' => $shift->cashier_id,
                'cashier_name' => $cashier->name ?? 'Unknown',
                'subtotal' => $subtotal,
                'tax_amount' => $totalTax,
                'discount_amount' => $effectiveTransactionDiscount,
                'discount_reason' => $discountReason,
                'discount_authorized_by' => $discountAuthorizedBy,
                'total' => $total,
                'currency' => $currency,
                'customer_name' => $customerName,
                'customer_identifier' => $customerIdentifier,
                'partner_id' => $partnerId,
                'contact_id' => $resolvedContactId,
                'is_voided' => false,
                'is_training' => $isTraining,
                'vat_breakdown_hash' => $vatHash,
                'payment_methods_hash' => $paymentHash,
                'consumption_mode' => $consumptionMode,
                'discount_breakdown' => $discountBreakdownData,
                'notes' => $notes,
                // Exchange link (Phase F / Task 36)
                'exchange_group_id' => $exchangeGroupId,
            ]);

            $receipt->id = $receiptId;
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

            // Advance terminal receipt-number sequence for ALL receipt types so that
            // every receipt gets a unique number. Training receipts share the same
            // counter because the receipt_number column has a UNIQUE index — without
            // the advance, two sequential training receipts on the same terminal
            // collide. The TRN- prefix keeps training and production in distinct
            // numbering namespaces but the underlying counter is shared.
            // Non-training receipt chain state (last_hash, chain_sequence on the
            // receipt row) is NOT updated here — that happens in
            // ReceiptFinalizationService::finalize(). Training receipts never enter
            // the fiscal chain, so chain_sequence stays NULL.
            $terminal->current_sequence = $sequence + 1;
            $terminal->save();

            // 11. Decrement stock for each line (with pessimistic locking)
            // Also collect receipt line IDs for batch allocation
            $createdReceiptLines = $receipt->lines()->orderBy('line_number')->get();

            foreach ($receiptLines as $index => $lineData) {
                $receiptLineModel = $createdReceiptLines[$index] ?? null;

                if ($lineData['product_id'] !== null) {
                    // Direct product line — decrement stock
                    $lineVariantId = isset($lineData['variant_id']) && $lineData['variant_id'] !== ''
                        ? (string) $lineData['variant_id']
                        : null;

                    $movement = $this->decrementStock(
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        locationId: $terminal->location_id,
                        productId: $lineData['product_id'],
                        quantity: $lineData['quantity'],
                        receiptId: $receipt->id,
                        cashierId: $shift->cashier_id,
                        policy: $stockPolicy,
                        variantId: $lineVariantId,
                    );

                    // Allocate batches using FEFO for batch-tracked products.
                    // Thread variantId so variant-bearing batch sales consume only
                    // variant-scoped batches (Task 18 — closes the null gap from Task 16b).
                    if ($movement !== null && $receiptLineModel !== null && $this->fefoService->productRequiresBatchTracking($lineData['product_id'])) {
                        $this->allocateBatches(
                            tenantId: $terminal->tenant_id,
                            locationId: $terminal->location_id,
                            productId: $lineData['product_id'],
                            quantity: $lineData['quantity'],
                            receiptId: $receipt->id,
                            receiptLineId: $receiptLineModel->id,
                            movementId: $movement->id,
                            variantId: $lineVariantId,
                        );
                    }
                } elseif ($lineData['composite_item_id'] !== null) {
                    // Composite item line — recursively deduct leaf-level product stock
                    $this->deductCompositeItemStock(
                        compositeItemId: $lineData['composite_item_id'],
                        saleQuantity: $lineData['quantity'],
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        locationId: $terminal->location_id,
                        receiptId: $receipt->id,
                        cashierId: $shift->cashier_id,
                        policy: $stockPolicy,
                    );
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

            DB::afterCommit(function () use ($freshReceipt) {
                // Dispatch ReceiptDrafted — the pre-seal lifecycle event.
                // ReceiptCreated (with fiscal hash + chain sequence) is dispatched later
                // by ReceiptFinalizationService::finalize() once the receipt is sealed.
                event(new ReceiptDrafted(
                    receiptId: $freshReceipt->id,
                    companyId: $freshReceipt->company_id,
                    terminalId: $freshReceipt->terminal_id,
                    cashierId: (string) $freshReceipt->cashier_id,
                    receiptNumber: $freshReceipt->receipt_number,
                    total: (string) $freshReceipt->total,
                    currency: $freshReceipt->currency,
                    postedAt: $freshReceipt->posted_at->toIso8601String(),
                ));
            });

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
            /** @var ModifierGroup $group */
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
            /** @var ModifierGroup $group */
            $group = $groupsById->get($mod['modifier_group_id']);
            /** @var Modifier|null $modifier */
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
     * Generate receipt number using location code, terminal code, and sequence.
     *
     * Format: {location_code}-{terminal_code}-{year}-{sequence}
     * Example: MAIN-POS01-2026-00000001
     *
     * Per NF525 and industry standards, the receipt number embeds both the
     * store/location and terminal identifiers for multi-location uniqueness.
     */
    private function generateReceiptNumber(Terminal $terminal, int $year, int $sequence): string
    {
        $locationCode = $this->resolveLocationCode($terminal);
        $terminalCode = $terminal->code;
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return "{$locationCode}-{$terminalCode}-{$year}-{$sequenceStr}";
    }

    /**
     * Generate training receipt number with TRN- prefix.
     *
     * Format: TRN-{location_code}-{terminal_code}-{year}-{sequence}
     * Example: TRN-MAIN-POS01-2026-00000001
     */
    private function generateTrainingReceiptNumber(Terminal $terminal, int $year, int $sequence): string
    {
        $locationCode = $this->resolveLocationCode($terminal);
        $terminalCode = $terminal->code;
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return "TRN-{$locationCode}-{$terminalCode}-{$year}-{$sequenceStr}";
    }

    /**
     * Resolve the location code for receipt number generation.
     *
     * Falls back to 'MAIN' if the location has no code set.
     */
    private function resolveLocationCode(Terminal $terminal): string
    {
        $code = $terminal->location->code;

        if ($code === null || $code === '') {
            return 'MAIN';
        }

        return strtoupper($code);
    }

    /**
     * Decrement stock for a product (optionally a specific variant) at a location,
     * with pessimistic lock.
     *
     * When `$variantId` is set the query scopes to the variant-scoped
     * `stock_levels` row (`product_id + variant_id + location_id`).
     * When null the query scopes to the product-level row
     * (`product_id + variant_id IS NULL + location_id`) — matching the
     * pre-variant behaviour exactly.
     *
     * The `variant_id` is threaded onto the `stock_movements` row so that
     * downstream reporting can dimension by variant.
     *
     * Creates a StockMovement audit record.
     *
     * `$policy` is the per-receipt POS over-stock policy resolved once at
     * the createReceipt boundary (spec §4.2): Block throws on insufficient
     * stock (the message clients pattern-match on); Warn/Off log a
     * warning and proceed into negative stock — the same direction the
     * fiscal-event projection path (PosCoreReceiptProjection) takes.
     *
     * @throws \RuntimeException If insufficient stock and policy is Block
     */
    private function decrementStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $cashierId,
        PosStockPolicy $policy,
        ?string $variantId = null,
    ): ?StockMovement {
        $stockLevelQuery = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId);

        if ($variantId !== null) {
            $stockLevelQuery->where('variant_id', $variantId);
        } else {
            $stockLevelQuery->whereNull('variant_id');
        }

        /** @var StockLevel|null $stockLevel */
        $stockLevel = $stockLevelQuery->lockForUpdate()->first();

        if ($stockLevel === null) {
            // No stock record — skip stock decrement for products without inventory tracking
            return null;
        }

        /** @var numeric-string $available */
        $available = $stockLevel->getAvailableQuantity();
        /** @var numeric-string $quantity */
        if (bccomp($available, $quantity, 4) < 0) {
            if ($policy === PosStockPolicy::Block) {
                // api.pos-stabilization.024 — scope Product::find by tenant + company
                // (the decrementStock arg list already carries both, so no change to
                // the call sites; this is a defense-in-depth gap closer that pins
                // the SQL shape against any future regression).
                $product = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($productId);
                $productName = $product->name ?? $productId;
                throw new \RuntimeException(
                    "Insufficient stock for '{$productName}'. Available: {$available}, Requested: {$quantity}"
                );
            }

            Log::warning('ReceiptCreationService: sale proceeding despite insufficient stock', [
                'policy' => $policy->value,
                'product_id' => $productId,
                'available' => $available,
                'requested' => $quantity,
                'receipt_id' => $receiptId,
            ]);
        }

        $quantityBefore = $stockLevel->quantity;
        /** @var numeric-string $stockQty */
        $stockQty = $stockLevel->quantity;
        // stock_levels.quantity and pos_receipt_lines.quantity are stored at
        // scale 4 (canonical quantity storage scale). Subtract at scale 4 so
        // sub-centi quantities are not truncated to zero.
        $quantityAfter = bcsub($stockQty, $quantity, 4); // 4 = canonical quantity storage scale

        // Update stock level
        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        // Create stock movement audit record.
        // `variant_id` is written when set so downstream reporting can
        // dimension by variant (Task 18). Column exists from Task 6.
        return StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'variant_id' => $variantId,
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
     * Apply promotion line discounts to receipt lines, recomputing VAT.
     *
     * @param  array<int, array<string, mixed>>  $receiptLines
     * @param  array<string, numeric-string>  $lineDiscounts  Keyed by product_id or composite_item_id
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $totalTax
     * @param  array<string, array{tax_rate: string, net_amount: string, vat_amount: string, gross_amount: string}>  $vatAggregates
     */
    private function applyPromotionLineDiscounts(
        array &$receiptLines,
        array $lineDiscounts,
        string &$subtotal,
        string &$totalTax,
        array &$vatAggregates,
    ): void {
        if (count($lineDiscounts) === 0) {
            return;
        }

        // Reset VAT aggregates to recompute from adjusted lines
        $vatAggregates = [];
        $subtotal = '0.00';
        $totalTax = '0.00';

        foreach ($receiptLines as &$lineData) {
            $lineProductId = $lineData['composite_item_id'] ?? $lineData['product_id'] ?? null;

            if ($lineProductId !== null && isset($lineDiscounts[$lineProductId])) {
                /** @var numeric-string $promoDiscount */
                $promoDiscount = $lineDiscounts[$lineProductId];

                // Add promotion discount to existing line discount
                /** @var numeric-string $existingDiscount */
                $existingDiscount = $lineData['discount_amount'] ?? '0.00';
                /** @var numeric-string $newDiscount */
                $newDiscount = bcadd($existingDiscount, $promoDiscount, $this->scale());

                // Recalculate line_total = (qty * unit_price) - total_discount
                /** @var numeric-string $qtyStr */
                $qtyStr = (string) $lineData['quantity'];
                /** @var numeric-string $upStr */
                $upStr = (string) $lineData['unit_price'];
                /** @var numeric-string $grossLineTotal */
                $grossLineTotal = bcmul($qtyStr, $upStr, $this->scale());

                // Cap discount at gross line total
                if (bccomp($newDiscount, $grossLineTotal, $this->scale()) > 0) {
                    $newDiscount = $grossLineTotal;
                }

                $lineData['discount_amount'] = $newDiscount;
                $lineData['line_total'] = bcsub($grossLineTotal, $newDiscount, $this->scale());

                // Recalculate VAT for this line
                /** @var numeric-string $taxRate */
                $taxRate = $lineData['tax_rate'];
                $taxRateDecimal = bcdiv($taxRate, '100', 6);
                $divisor = bcadd('1', $taxRateDecimal, 6);
                /** @var numeric-string $lineTotal */
                $lineTotal = $lineData['line_total'];
                $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());

                $lineData['tax_amount'] = $taxAmount;
            }

            // Recompute aggregates from all lines (including unmodified ones)
            /** @var numeric-string $lineTaxRate */
            $lineTaxRate = $lineData['tax_rate'];
            $lineTaxRateDecimal = bcdiv($lineTaxRate, '100', 6);
            $lineDivisor = bcadd('1', $lineTaxRateDecimal, 6);
            /** @var numeric-string $lt */
            $lt = $lineData['line_total'];
            $lineNet = bcdiv($lt, $lineDivisor, $this->scale());
            $lineTax = bcsub($lt, $lineNet, $this->scale());

            $subtotal = bcadd($subtotal, $lineNet, $this->scale());
            $totalTax = bcadd($totalTax, $lineTax, $this->scale());

            $this->aggregateVat($vatAggregates, $lineTaxRate, $lineNet, $lineTax, $lt);
        }
        unset($lineData);

        // Recompute VAT aggregates from aggregated net_amount (same as step 4a-fix)
        /** @var numeric-string $recomputedTotalTax */
        $recomputedTotalTax = '0.00';
        foreach ($vatAggregates as &$vatData) {
            $vatData['vat_amount'] = $this->roundVat($vatData['net_amount'], $vatData['tax_rate']);
            /** @var numeric-string $vatNetAmount */
            $vatNetAmount = $vatData['net_amount'];
            /** @var numeric-string $vatAmount */
            $vatAmount = $vatData['vat_amount'];
            $vatData['gross_amount'] = bcadd($vatNetAmount, $vatAmount, $this->scale());
            $recomputedTotalTax = bcadd($recomputedTotalTax, $vatAmount, $this->scale());
        }
        unset($vatData);
        $totalTax = $recomputedTotalTax;
    }

    /**
     * Aggregate VAT amounts by rate.
     *
     * @param  array<string, array{tax_rate: string, net_amount: string, vat_amount: string, gross_amount: string}>  $vatAggregates
     * @param  numeric-string  $netAmount
     * @param  numeric-string  $taxAmount
     * @param  numeric-string  $lineTotal
     */
    private function aggregateVat(array &$vatAggregates, string $taxRate, string $netAmount, string $taxAmount, string $lineTotal): void
    {
        $rateKey = $taxRate;
        if (! isset($vatAggregates[$rateKey])) {
            $vatAggregates[$rateKey] = [
                'tax_rate' => $taxRate,
                'net_amount' => '0.00',
                'vat_amount' => '0.00',
                'gross_amount' => '0.00',
            ];
        }
        /** @var numeric-string $existingNet */
        $existingNet = $vatAggregates[$rateKey]['net_amount'];
        /** @var numeric-string $existingVat */
        $existingVat = $vatAggregates[$rateKey]['vat_amount'];
        /** @var numeric-string $existingGross */
        $existingGross = $vatAggregates[$rateKey]['gross_amount'];

        $vatAggregates[$rateKey]['net_amount'] = bcadd($existingNet, $netAmount, $this->scale());
        $vatAggregates[$rateKey]['vat_amount'] = bcadd($existingVat, $taxAmount, $this->scale());
        $vatAggregates[$rateKey]['gross_amount'] = bcadd($existingGross, $lineTotal, $this->scale());
    }

    /**
     * Recursively deduct stock for a composite item by exploding its recipe
     * down to leaf-level products.
     *
     * `$policy` is the per-receipt POS over-stock policy (spec §4.2),
     * threaded unchanged into every leaf decrementStock() call so composite
     * leaf deduction enforces the same behavior as direct product lines.
     */
    private function deductCompositeItemStock(
        string $compositeItemId,
        string $saleQuantity,
        string $tenantId,
        string $companyId,
        string $locationId,
        string $receiptId,
        string $cashierId,
        PosStockPolicy $policy,
        int $depth = 0,
    ): void {
        if ($depth > 10) {
            return;
        }

        $compositeItem = CompositeItem::with('activeRecipe.lines')->find($compositeItemId);
        if ($compositeItem === null || $compositeItem->activeRecipe === null) {
            return;
        }

        /** @var RecipeLine $line */
        foreach ($compositeItem->activeRecipe->lines as $line) {
            // required quantity = recipe line qty * sale quantity
            $requiredQty = bcmul(
                CurrencyScale::bcformat($line->quantity, 4),
                CurrencyScale::bcformat($saleQuantity, 4),
                4
            );

            if ($line->component_type === ComponentType::CompositeItem) {
                $this->deductCompositeItemStock(
                    compositeItemId: $line->component_id,
                    saleQuantity: $requiredQty,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    locationId: $locationId,
                    receiptId: $receiptId,
                    cashierId: $cashierId,
                    policy: $policy,
                    depth: $depth + 1,
                );
            } else {
                $this->decrementStock(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    locationId: $locationId,
                    productId: $line->component_id,
                    quantity: $requiredQty,
                    receiptId: $receiptId,
                    cashierId: $cashierId,
                    policy: $policy,
                );
            }
        }
    }

    /**
     * For a fixed_bundle composite item, decompose the combo price proportionally
     * across components based on their standalone prices, respecting different VAT rates.
     *
     * @return array<int, array{name: string, share: string, tax_rate: string, net_amount: string, tax_amount: string}>
     */
    public function decomposeFixedBundleVat(CompositeItem $comboItem, string $comboPrice): array
    {
        $recipe = $comboItem->activeRecipe;
        if ($recipe === null) {
            return [];
        }

        $recipe->loadMissing('lines.product', 'lines.compositeItemComponent');

        // Collect standalone prices for each component
        $components = [];
        /** @var numeric-string $totalStandalonePrice */
        $totalStandalonePrice = '0';

        /** @var RecipeLine $line */
        foreach ($recipe->lines as $line) {
            $name = '';
            $standalonePrice = '0';
            $taxRate = '0';

            if ($line->component_type === ComponentType::CompositeItem && $line->compositeItemComponent !== null) {
                $name = $line->compositeItemComponent->name;
                $standalonePrice = (string) $line->compositeItemComponent->base_price;
                $taxRate = (string) ($line->compositeItemComponent->tax_rate ?? '0');
            } elseif ($line->product !== null) {
                $name = $line->product->name;
                $standalonePrice = (string) ($line->product->sale_price ?? '0');
                $taxRate = (string) ($line->product->tax_rate ?? '0');
            }

            $priceStr = CurrencyScale::bcformat($standalonePrice, 4);
            $qtyStr = CurrencyScale::bcformat($line->quantity, 4);
            /** @var numeric-string $priceStr */
            /** @var numeric-string $qtyStr */
            $lineStandalone = bcmul($priceStr, $qtyStr, 4);
            $totalStandalonePrice = bcadd($totalStandalonePrice, $lineStandalone, 4);

            $components[] = [
                'name' => $name,
                'standalone_price' => $lineStandalone,
                'tax_rate' => $taxRate,
            ];
        }

        if (bccomp($totalStandalonePrice, '0', 4) <= 0) {
            return [];
        }

        $result = [];
        /** @var numeric-string $allocatedTotal */
        $allocatedTotal = '0';
        $lastIndex = count($components) - 1;

        /** @var numeric-string $comboPriceNumeric */
        $comboPriceNumeric = $comboPrice;

        foreach ($components as $index => $comp) {
            /** @var numeric-string $compStandalone */
            $compStandalone = $comp['standalone_price'];
            /** @var numeric-string $compTaxRate */
            $compTaxRate = $comp['tax_rate'];

            if ($index === $lastIndex) {
                // Last component gets remainder to avoid rounding drift
                $share = bcsub($comboPriceNumeric, $allocatedTotal, $this->scale());
            } else {
                $share = bcmul(
                    $comboPriceNumeric,
                    bcdiv($compStandalone, $totalStandalonePrice, 10),
                    $this->scale()
                );
            }
            $allocatedTotal = bcadd($allocatedTotal, $share, $this->scale());

            // Calculate net and tax from the share (price is TTC)
            $taxRateDecimal = bcdiv($compTaxRate, '100', 6);
            $divisor = bcadd('1', $taxRateDecimal, 6);
            $netAmount = bcdiv($share, $divisor, $this->scale());
            $taxAmount = bcsub($share, $netAmount, $this->scale());

            $result[] = [
                'name' => $comp['name'],
                'share' => $share,
                'tax_rate' => $comp['tax_rate'],
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
            ];
        }

        return $result;
    }

    /**
     * Allocate batches using FEFO for a POS receipt line.
     *
     * Atomically consumes batch-level stock (FEFO, row-locked via
     * {@see FEFOInventoryService::consumeBatchesAtomically()}) and snapshots each
     * consumed batch into a ReceiptLineBatchAllocation row for traceability.
     *
     * STRICT FULFILLMENT (§10.3): if batch stock cannot fully cover the line,
     * InsufficientBatchStockException bubbles out of this method and aborts the
     * surrounding receipt transaction BEFORE commit — the sale is rejected
     * rather than committed with a silent batch shortfall. This replaces the old
     * "log a warning and proceed" path, which let a batch-tracked sale commit
     * even though no batch could be drawn down (a real concurrency hole when two
     * cashiers received identical read-only FEFO suggestions).
     *
     * `$variantId` is threaded into `consumeBatchesAtomically` so that
     * variant-bearing batch sales consume only variant-scoped batches
     * (Task 18 — closes the null gap Task 16b left). When null, only
     * product-level batches (variant_id IS NULL) are consumed.
     *
     * @param  numeric-string  $quantity  Line quantity to consume (decimal string)
     *
     * @throws InsufficientBatchStockException
     */
    private function allocateBatches(
        string $tenantId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $receiptLineId,
        string $movementId,
        ?string $variantId = null,
    ): void {
        $result = $this->fefoService->consumeBatchesAtomically(
            tenantId: $tenantId,
            productId: $productId,
            locationId: $locationId,
            quantity: $quantity,
            movementId: $movementId,
            strictFulfillment: true,
            variantId: $variantId,
        );

        foreach ($result->consumed as $consumedBatch) {
            $batch = Batch::find($consumedBatch->batchId);

            // Create allocation record (snapshot batch info for traceability).
            ReceiptLineBatchAllocation::create([
                'receipt_id' => $receiptId,
                'receipt_line_id' => $receiptLineId,
                'batch_id' => $consumedBatch->batchId,
                'quantity' => $consumedBatch->quantityConsumed,
                'batch_number' => $batch?->batch_number,
                'expiry_date' => $consumedBatch->expiryDate,
            ]);
        }
    }
}
