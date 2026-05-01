<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\DTOs\SyncReceiptPayload;
use App\Modules\POS\Application\DTOs\SyncReceiptResult;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Enums\SyncStatus;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Exceptions\OfflineFiscalHashMismatchException;
use App\Modules\POS\Domain\Exceptions\OfflineReceiptVersionMismatchException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
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
 * - v3-aware finalization: delegates hash computation to ReceiptFinalizationService
 * - offline_fiscal_hash verification: server recomputes and compares against payload hash
 */
final class ReceiptSyncService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ReceiptFinalizationService $finalizationService,
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
     * @return array<int, SyncReceiptResult> Per-receipt sync results
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
                if ($result->status === SyncStatus::Failed) {
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
     * Delegates finalization (hash computation + chain advance) to ReceiptFinalizationService.
     * After finalize, verifies the server-computed hash matches the payload's offline_fiscal_hash.
     */
    private function syncSingleReceipt(SyncReceiptPayload $payload): SyncReceiptResult
    {
        // 1. Idempotency check: if receipt with this key already exists, return duplicate
        $existing = Receipt::where('idempotency_key', $payload->idempotencyKey)->first();
        if ($existing !== null) {
            // Refresh the terminal so the echo reflects the current persisted state
            // (not the idempotency-hit terminal's pre-modification state).
            $terminalForEcho = Terminal::where('id', $existing->terminal_id)->first();

            return SyncReceiptResult::duplicate(
                $payload->idempotencyKey,
                $existing->id,
                $existing->fiscal_hash,
                terminalLastHash: $terminalForEcho?->last_hash,
                terminalHashSequence: $terminalForEcho !== null
                    ? $terminalForEcho->current_sequence - 1
                    : null,
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

            // 3. Validate fiscal_schema_version: payload version must match terminal version.
            //    Force-drain cutover policy: a v3 terminal refuses any v2 payload, and
            //    a v2 terminal refuses any v3 payload. No compatibility window.
            $terminalVersion = $terminal->fiscal_schema_version;
            $payloadVersion = $payload->fiscalSchemaVersion;

            if ($payloadVersion !== $terminalVersion) {
                $exception = $payloadVersion === 2 && $terminalVersion === 3
                    ? OfflineReceiptVersionMismatchException::payloadV2AgainstV3Terminal($terminal->id)
                    : OfflineReceiptVersionMismatchException::versionMismatch($terminal->id, $payloadVersion, $terminalVersion);

                return SyncReceiptResult::failed(
                    $payload->idempotencyKey,
                    $exception->getMessage(),
                );
            }

            // 4. Validate hash chain continuity
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

            // 5. Find active shift (or any shift for this terminal if created offline)
            $shift = Shift::where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->first();

            $cashierId = $payload->operatorId;
            $cashier = User::find($cashierId);
            $cashierName = $cashier->name ?? 'Unknown';

            // 6. Resolve line items and compute VAT
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
                        // Normalize tax_rate to 2 decimal places so the vatBreakdown hash is
                        // consistent regardless of how the column is returned by the DB driver
                        // (e.g. 0 vs '0.00' vs '0' for a nullable decimal(5,2) column).
                        $taxRate = CurrencyScale::bcformat($product->tax_rate ?? '0', 2);
                    }
                } elseif ($compositeItemId !== null) {
                    $compositeItem = CompositeItem::find($compositeItemId);
                    if ($compositeItem !== null) {
                        $sellableName = $compositeItem->getSellableName();
                        $sellableCode = $compositeItem->code;
                        $sellableUnit = $compositeItem->getSellableUnit() ?? 'pc';
                        $taxRate = CurrencyScale::bcformat($compositeItem->tax_rate ?? '0', 2);
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

            // Use client-provided totals (they are the fiscal truth from the offline sale).
            // Normalize to the currency scale using bcadd so that the stored value has
            // the correct decimal precision (e.g. '20.00' → '20.000' for TND=3).
            // This is required for v2 hash parity because ReceiptHashService::serializeForHashing()
            // uses $receipt->total as-is; a precision mismatch ('20.00' vs '20.000') produces a
            // different hash than what the ReceiptCreationService (online path) would produce.
            $scale = $this->scale();
            /** @var numeric-string $payloadTotal */
            $payloadTotal = $payload->total;
            /** @var numeric-string $payloadSubtotal */
            $payloadSubtotal = $payload->subtotal;
            /** @var numeric-string $payloadTaxAmount */
            $payloadTaxAmount = $payload->taxAmount;
            /** @var numeric-string $payloadDiscountAmount */
            $payloadDiscountAmount = $payload->discountAmount;
            $total = bcadd($payloadTotal, '0', $scale);
            $subtotalNorm = bcadd($payloadSubtotal, '0', $scale);
            $taxAmountNorm = bcadd($payloadTaxAmount, '0', $scale);
            $discountAmountNorm = bcadd($payloadDiscountAmount, '0', $scale);
            $changeDueNorm = $payload->changeDue !== null
                ? (static function (string $v) use ($scale): string {
                    /** @var numeric-string $v */
                    return bcadd($v, '0', $scale);
                })($payload->changeDue)
                : null;

            // Compute sub-hashes required by v2 ReceiptHashService.
            // These are also stored for auditability in the v3 path (the v3 computer
            // does not read them, but the columns are NOT NULL so they must be populated).
            $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
            // Payment rows are not yet persisted when this hash is computed; for the sync
            // path (same as the original code) we pass an empty array.  The hash serves
            // as a column filler; the v2 hash chain itself uses the serialized receipt
            // fields (not this standalone payment-hash column directly).
            $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

            $postedAt = Carbon::parse($payload->createdAt);

            // 7. Get sequence / year from terminal.
            //    If the receipt was created in a new year (offline year-boundary scenario),
            //    reset the terminal's sequence counter and persist immediately so that
            //    ReceiptFinalizationService reads the correct chain state.
            $currentYear = (int) $postedAt->format('Y');
            if ($terminal->current_year !== $currentYear) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
                $terminal->save();
            }

            // 8. Create receipt as pending_seal — no inline hash, no terminal advance yet.
            //    ReceiptFinalizationService will compute the hash and advance the chain.
            $receiptId = Str::uuid()->toString();
            $receipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $payload->receiptNumber,
                'receipt_type' => ReceiptType::Sale,
                'chain_sequence' => null,   // set by finalizationService
                'receipt_year' => $currentYear,
                'previous_hash' => null,    // set by finalizationService
                'posted_at' => $postedAt,
                'cashier_id' => $cashierId,
                'cashier_name' => $cashierName,
                'subtotal' => $subtotalNorm,
                'tax_amount' => $taxAmountNorm,
                'discount_amount' => $discountAmountNorm,
                'total' => $total,
                'change_due' => $changeDueNorm,
                'currency' => $payload->currency,
                'consumption_mode' => $payload->consumptionMode !== null
                    ? ConsumptionMode::from($payload->consumptionMode)
                    : null,
                'table_id' => $payload->tableId,
                'fiscal_status' => FiscalStatus::PendingSeal,
                'is_voided' => false,
                'vat_breakdown_hash' => $vatHash,
                'payment_methods_hash' => $paymentHash,
                'idempotency_key' => $payload->idempotencyKey,
                'synced_at' => Carbon::now(),
                'notes' => null,
            ]);

            $receipt->id = $receiptId;
            $receipt->setRelation('terminal', $terminal);
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

            // 11. Create payment records
            //     (Phase 1: voucher ledger entries are empty.)
            //     payment_method_code is an immutable snapshot of payment_methods.code
            //     bound into the v3 canonical fiscal hash (Codex review B2, 2026-04-30).
            //
            //     Codex review B3 (2026-04-30): the offline POS sealed the v3 hash
            //     with `method_code`, `instrument_type`, and `instrument_serial`
            //     populated. We MUST persist them on the synced row or the server's
            //     post-finalize hash recomputation reads null and rejects the
            //     receipt as a chain break. instrument_type is coerced through the
            //     PaymentInstrumentKind enum so an unknown string fails fast.
            //
            //     B3-followup audit (Finding 2, 2026-05-01): the previous fallback
            //     to a live `PaymentMethod::code` lookup when `method_code` was
            //     absent has been deleted. `method_code` is now REQUIRED on the
            //     wire (`SyncReceiptsRequest.php`) and required-or-throw in the DTO
            //     (`SyncReceiptPayload::fromArray()`). The client-supplied snapshot
            //     is the only acceptable input — anything else risks silent hash-
            //     input substitution that the audit explicitly flagged.
            foreach ($payload->payments as $entry) {
                $method = PaymentMethod::findOrFail($entry['payment_method_id']);

                // Codex review B4 (2026-04-30): defense-in-depth at the sync
                // writer. The HTTP request validator (SyncReceiptsRequest)
                // rejects this shape with 422, but a programmatic caller —
                // a queue retry job, a backfill script, a future controller —
                // bypasses FormRequest validation and constructs
                // SyncReceiptPayload directly. Without this guard, a v3
                // receipt could still be sealed with `method_code = store_voucher`
                // and `instrument_serial = null` — the same fiscal-hash hole
                // B4 closes "once and for all." The throw happens before the
                // ReceiptPayment write so the enclosing DB::transaction()
                // rolls back cleanly with no partial chain state. The
                // syncBatch() catch block converts this to SyncStatus::Failed
                // for batch reporting.
                $methodCode = (string) $entry['method_code'];
                if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
                    $instrumentTypeInput = $entry['instrument_type'] ?? null;
                    $instrumentSerialInput = $entry['instrument_serial'] ?? null;
                    if (
                        ! is_string($instrumentTypeInput) || $instrumentTypeInput === ''
                        || ! is_string($instrumentSerialInput) || $instrumentSerialInput === ''
                    ) {
                        throw InstrumentRequiredException::forMethodCode($methodCode);
                    }
                }

                $instrumentTypeValue = $entry['instrument_type'] ?? null;
                $instrumentType = $instrumentTypeValue !== null && $instrumentTypeValue !== ''
                    ? PaymentInstrumentKind::from($instrumentTypeValue)
                    : null;
                ReceiptPayment::create([
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                    'payment_method_id' => $entry['payment_method_id'],
                    'payment_type' => $method->code,
                    'payment_method_code' => $entry['method_code'],
                    'amount' => $entry['amount'],
                    'card_last_four' => $entry['card_last_four'] ?? null,
                    'transaction_reference' => $entry['transaction_reference'] ?? null,
                    'instrument_type' => $instrumentType,
                    'instrument_serial' => $entry['instrument_serial'] ?? null,
                ]);
            }

            // 12. Finalize: ReceiptFinalizationService computes the hash using the
            //     version-appropriate path (v2 legacy or v3 canonical) and advances
            //     the terminal's chain counters under the existing FOR UPDATE lock.
            //     The service runs its own DB::transaction() which is nested inside
            //     ours — it will reuse this transaction (Laravel savepoints).
            $receipt = $this->finalizationService->finalize($receipt);
            $fiscalHash = $receipt->fiscal_hash;

            // 13. Verify server-computed hash against the offline hash from the payload.
            //     Mismatch = tamper / version drift → throw to roll back the transaction.
            if ($fiscalHash !== $payload->offlineFiscalHash) {
                throw OfflineFiscalHashMismatchException::create(
                    $payload->receiptNumber,
                    $payload->offlineFiscalHash,
                    (string) $fiscalHash,
                );
            }

            // 14. Decrement stock for product lines
            $terminal->refresh();
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

            return SyncReceiptResult::synced(
                $payload->idempotencyKey,
                $receipt->id,
                (string) $fiscalHash,
                terminalLastHash: $terminal->last_hash,
                terminalHashSequence: $terminal->current_sequence - 1,
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
