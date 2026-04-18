<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Domain\CurrencyScale;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\DTOs\SyncReceiptPayload;
use App\Modules\POS\Application\DTOs\SyncReceiptResult;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for syncing offline POS receipts to the server.
 *
 * Handles batch receipt synchronization with:
 * - Idempotency via client-generated keys
 * - Hash chain continuity validation
 * - Stock decrement per receipt
 * - Chain break propagation (if receipt N fails, N+1..N+M all fail)
 */
final class ReceiptSyncService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Sync a batch of offline receipts for a terminal.
     *
     * Receipts must be ordered by chain sequence. Once a hash chain
     * validation fails, all subsequent receipts are marked as chain_broken.
     *
     * @param  array<int, SyncReceiptPayload>  $payloads  Ordered array of receipt payloads
     * @return array<int, SyncReceiptResult>  Per-receipt sync results
     */
    public function syncBatch(array $payloads): array
    {
        if (count($payloads) === 0) {
            return [];
        }

        $results = [];
        $chainBroken = false;

        foreach ($payloads as $payload) {
            if ($chainBroken) {
                $results[] = SyncReceiptResult::chainBroken($payload->idempotencyKey);

                continue;
            }

            try {
                $result = $this->syncSingleReceipt($payload);
                $results[] = $result;

                // If sync failed (not duplicate), break the chain for subsequent receipts
                if ($result->status === \App\Modules\POS\Domain\Enums\SyncStatus::Failed) {
                    $chainBroken = true;
                }
            } catch (\Throwable $e) {
                Log::error('Receipt sync failed unexpectedly', [
                    'idempotency_key' => $payload->idempotencyKey,
                    'error' => $e->getMessage(),
                ]);
                $results[] = SyncReceiptResult::failed(
                    $payload->idempotencyKey,
                    $e->getMessage(),
                );
                $chainBroken = true;
            }
        }

        return $results;
    }

    /**
     * Sync a single offline receipt.
     *
     * Checks idempotency, validates hash chain, creates receipt with stock decrement.
     */
    private function syncSingleReceipt(SyncReceiptPayload $payload): SyncReceiptResult
    {
        // 1. Idempotency check: if receipt with this key already exists, return duplicate
        $existing = Receipt::where('idempotency_key', $payload->idempotencyKey)->first();
        if ($existing !== null) {
            return SyncReceiptResult::duplicate(
                $payload->idempotencyKey,
                $existing->id,
                $existing->fiscal_hash,
            );
        }

        $companyId = $this->companyContext->requireCompanyId();

        return DB::transaction(function () use ($payload, $companyId): SyncReceiptResult {
            // 2. Lock terminal for update
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $payload->terminalId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $terminal->isActive()) {
                return SyncReceiptResult::failed(
                    $payload->idempotencyKey,
                    'Terminal is not active',
                );
            }

            // 3. Validate hash chain continuity
            // The client's previous_hash must match the terminal's current last_hash
            // Both can be null/empty for the first receipt in chain
            $clientPreviousHash = $payload->previousHash ?? '';
            $terminalLastHash = $terminal->last_hash ?? '';
            if ($clientPreviousHash !== $terminalLastHash) {
                return SyncReceiptResult::failed(
                    $payload->idempotencyKey,
                    'Hash chain break: client previous_hash does not match terminal last_hash',
                );
            }

            // 4. Find active shift (or any shift for this terminal if created offline)
            $shift = Shift::where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->first();

            $cashierId = $payload->operatorId;
            $cashier = User::find($cashierId);
            $cashierName = $cashier->name ?? 'Unknown';

            // 5. Resolve line items and compute VAT
            $receiptLines = [];
            $vatAggregates = [];
            $subtotal = '0.00';
            $totalTax = '0.00';

            foreach ($payload->lines as $index => $lineData) {
                $productId = $lineData['product_id'] ?? null;
                $compositeItemId = $lineData['composite_item_id'] ?? null;

                // Resolve product/item names for server-side snapshot
                $sellableName = 'Unknown Product';
                $sellableCode = '';
                $sellableUnit = 'pc';
                $taxRate = '0.00';

                if ($productId !== null) {
                    $product = Product::find($productId);
                    if ($product !== null) {
                        $sellableName = $product->name;
                        $sellableCode = $product->sku ?? $product->barcode ?? '';
                        $sellableUnit = $product->unit ?? 'pc';
                        $taxRate = (string) ($product->tax_rate ?? '0.00');
                    }
                } elseif ($compositeItemId !== null) {
                    $compositeItem = \App\Modules\Catalog\Domain\Entities\CompositeItem::find($compositeItemId);
                    if ($compositeItem !== null) {
                        $sellableName = $compositeItem->getSellableName();
                        $sellableCode = $compositeItem->code;
                        $sellableUnit = $compositeItem->getSellableUnit() ?? 'pc';
                        $taxRate = (string) ($compositeItem->tax_rate ?? '0.00');
                    }
                }

                /** @var numeric-string $quantity */
                $quantity = (string) $lineData['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineData['unit_price'];
                $grossLineTotal = bcmul($quantity, $unitPrice, $this->scale());

                // Resolve line discount
                $discountAmount = isset($lineData['discount_amount'])
                    ? (string) $lineData['discount_amount']
                    : '0.00';

                /** @var numeric-string $discountAmountStr */
                $discountAmountStr = $discountAmount;
                $lineTotal = bcsub($grossLineTotal, $discountAmountStr, $this->scale());

                // Calculate tax (tax-inclusive)
                $taxRateDecimal = bcdiv($taxRate, '100', 6);
                $divisor = bcadd('1', $taxRateDecimal, 6);
                $netAmount = bcdiv($lineTotal, $divisor, $this->scale());
                $taxAmount = bcsub($lineTotal, $netAmount, $this->scale());

                $subtotal = bcadd($subtotal, $netAmount, $this->scale());
                $totalTax = bcadd($totalTax, $taxAmount, $this->scale());

                $receiptLines[] = [
                    'line_number' => $index + 1,
                    'product_id' => $compositeItemId !== null ? null : $productId,
                    'composite_item_id' => $compositeItemId,
                    'product_code' => $sellableCode,
                    'product_name' => $sellableName,
                    'product_description' => null,
                    'quantity' => $quantity,
                    'unit' => $sellableUnit,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'modifiers' => $lineData['modifiers'] ?? null,
                    'discount_amount' => $discountAmount,
                    'discount_reason' => $lineData['discount_reason'] ?? null,
                ];

                // Aggregate VAT
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

            // Recalculate VAT aggregates to match DB precision
            /** @var numeric-string $totalTax */
            $totalTax = '0.00';
            foreach ($vatAggregates as &$vatData) {
                /** @var numeric-string $vatNet */
                $vatNet = $vatData['net_amount'];
                /** @var numeric-string $vatRate */
                $vatRate = $vatData['tax_rate'];
                $vatData['vat_amount'] = $this->roundVat($vatNet, $vatRate);
                /** @var numeric-string $vatAmount */
                $vatAmount = $vatData['vat_amount'];
                $vatData['gross_amount'] = bcadd($vatNet, $vatAmount, $this->scale());
                $totalTax = bcadd($totalTax, $vatAmount, $this->scale());
            }
            unset($vatData);

            // Use client-provided totals (they are the fiscal truth from the offline sale)
            $total = $payload->total;

            // 6. Generate hashes
            $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
            $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

            $postedAt = Carbon::parse($payload->createdAt);
            $previousHash = $terminal->last_hash;

            // 7. Get sequence from terminal
            $currentYear = (int) $postedAt->format('Y');
            if ($terminal->current_year !== $currentYear) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
            }
            $sequence = $terminal->current_sequence;

            // 8. Create receipt
            $receiptId = Str::uuid()->toString();
            $receipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $payload->receiptNumber,
                'receipt_type' => ReceiptType::Sale,
                'chain_sequence' => $sequence,
                'receipt_year' => $currentYear,
                'previous_hash' => $previousHash,
                'posted_at' => $postedAt,
                'cashier_id' => $cashierId,
                'cashier_name' => $cashierName,
                'subtotal' => $payload->subtotal,
                'tax_amount' => $payload->taxAmount,
                'discount_amount' => $payload->discountAmount,
                'total' => $total,
                'currency' => $payload->currency,
                'consumption_mode' => $payload->consumptionMode !== null
                    ? \App\Modules\POS\Domain\Enums\ConsumptionMode::from($payload->consumptionMode)
                    : null,
                'table_id' => $payload->tableId,
                'fiscal_status' => 'fiscalized',
                'is_voided' => false,
                'vat_breakdown_hash' => $vatHash,
                'payment_methods_hash' => $paymentHash,
                'idempotency_key' => $payload->idempotencyKey,
                'synced_at' => Carbon::now(),
                'notes' => null,
            ]);

            // Calculate fiscal hash
            $receipt->id = $receiptId;
            $receipt->setRelation('terminal', $terminal);
            $fiscalHash = $this->receiptHashService->calculateHash($receipt, $previousHash);
            $receipt->fiscal_hash = $fiscalHash;
            $receipt->save();

            // 9. Create receipt lines
            foreach ($receiptLines as $lineData) {
                ReceiptLine::create(array_merge($lineData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                ]));
            }

            // 10. Create VAT details
            foreach ($vatAggregates as $vatData) {
                ReceiptVatDetail::create(array_merge($vatData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                ]));
            }

            // 11. Update terminal sequence and hash chain
            $terminal->current_sequence = $sequence + 1;
            $terminal->last_hash = $fiscalHash;
            $terminal->save();

            // 12. Decrement stock for product lines
            foreach ($receiptLines as $lineData) {
                if ($lineData['product_id'] !== null) {
                    $this->decrementStock(
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        locationId: $terminal->location_id,
                        productId: $lineData['product_id'],
                        quantity: $lineData['quantity'],
                        receiptId: $receipt->id,
                        cashierId: $cashierId,
                    );
                }
            }

            // 13. Create payment records — loop over the payments array
            foreach ($payload->payments as $entry) {
                $method = \App\Modules\Treasury\Domain\PaymentMethod::findOrFail($entry['payment_method_id']);
                ReceiptPayment::create([
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                    'payment_method_id' => $entry['payment_method_id'],
                    'payment_type' => $method->code,
                    'amount' => $entry['amount'],
                    'card_last_four' => $entry['card_last_four'] ?? null,
                    'transaction_reference' => $entry['transaction_reference'] ?? null,
                ]);
            }

            return SyncReceiptResult::synced(
                $payload->idempotencyKey,
                $receipt->id,
                $fiscalHash,
            );
        });
    }

    /**
     * Decrement stock for a product at a location.
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
            return;
        }

        /** @var numeric-string $available */
        $available = $stockLevel->getAvailableQuantity();
        /** @var numeric-string $qty */
        $qty = $quantity;
        if (bccomp($available, $qty, 4) < 0) {
            // For offline sync, log warning but don't block — the sale already happened
            Log::warning('Insufficient stock during offline receipt sync', [
                'product_id' => $productId,
                'available' => $available,
                'requested' => $quantity,
                'receipt_id' => $receiptId,
            ]);
        }

        /** @var numeric-string $stockQty */
        $stockQty = $stockLevel->quantity;
        $quantityBefore = $stockQty;
        $quantityAfter = bcsub($stockQty, $qty, 2);

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

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
            'reference' => 'POS Offline Sync',
            'reference_type' => 'pos_receipt',
            'reference_id' => $receiptId,
            'notes' => "Stock issued via offline POS sync (receipt: {$receiptId})",
            'user_id' => $cashierId,
            'is_historical' => false,
        ]);
    }

    /**
     * Round VAT amount to match PostgreSQL precision.
     */
    private function roundVat(string $netAmount, string $taxRate): string
    {
        $raw = (float) $netAmount * (float) $taxRate / 100.0;

        return CurrencyScale::bcformat(round($raw, $this->scale()), $this->scale());
    }
}
