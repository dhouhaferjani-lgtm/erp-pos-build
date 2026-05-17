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
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
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
 *
 * Task 21 status — **superseded by `PosCoreReceiptProjection`** for the
 * device-authored fiscal-event path. The voucher-redemption (`:683-710`)
 * and stock-movement (`:713-735`) sections have been relocated into the
 * projector; the service body remains intact so the legacy
 * `POST /api/pos/sync/receipts` endpoint and its callers continue to work
 * until Task 25 retires the offline-sync HTTP surface in favor of the
 * Task 20 `POST /api/v1/pos/sync/fiscal-events` endpoint. New writers
 * MUST go through the projector — do not extend this service.
 */
final class ReceiptSyncService
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptHashService $receiptHashService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ReceiptFinalizationService $finalizationService,
        private readonly VoucherRedemptionService $voucherRedemptionService,
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
            // Codex round-2 P2 (2026-05-10): training receipts are independent
            // of the production fiscal chain. A `$chainBroken` flag set by an
            // earlier production failure must NOT short-circuit a subsequent
            // training receipt — training receipts don't depend on the chain
            // state and are still safe to attempt. Combined with the round-1
            // P2 fix below (gate the flag SET on `! isTraining`), the chain-
            // broken propagation is fully scoped to production-only receipts:
            //   - production-fail → next production: chain_broken (correct).
            //   - production-fail → next training: attempted (this gate).
            //   - training-fail → next production: attempted (round-1 fix).
            //   - training-fail → next training: attempted (both gates).
            if ($chainBroken && ! $payload->isTraining) {
                $results[] = SyncReceiptResult::chainBroken($payload->idempotencyKey);

                continue;
            }

            try {
                $result = $this->syncSingleReceipt($payload);
                $results[] = $result;

                // If sync failed (not duplicate), break the chain for subsequent receipts.
                // Codex round-1 P2 (2026-05-10): training receipts are independent
                // of the fiscal chain, so a failed training receipt must NOT poison
                // subsequent production receipts in the same batch. Only failures
                // from receipts that participate in the production chain
                // (i.e. non-training) propagate as `chain_broken` to the rest.
                if ($result->status === SyncStatus::Failed && ! $payload->isTraining) {
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
                // Codex round-1 P2 (2026-05-10): same gate as the syncStatus
                // path above — a thrown exception during a training receipt
                // sync must not poison subsequent production receipts.
                if (! $payload->isTraining) {
                    $chainBroken = true;
                }
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
        // 1. Idempotency check: if receipt with this key already exists, return duplicate.
        //    Round-3 Codex Finding 2 — the SELECT MUST be scoped by authenticated
        //    tenant_id + company_id from CompanyContext. Pre-fix this lookup ran
        //    BEFORE company context was applied, so a tenant-A submitter could
        //    collide on a tenant-B idempotency_key and receive the foreign
        //    receipt id, fiscal_hash, and terminal hash state through the
        //    duplicate-response branch (a live fiscal-data leak).
        $company = $this->companyContext->requireCompany();
        $existing = Receipt::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('idempotency_key', $payload->idempotencyKey)
            ->first();
        if ($existing !== null) {
            // Refresh the terminal so the echo reflects the current persisted
            // state. Anchor the Terminal SELECT on the company-scoped
            // $existing row's tenant + company so the echo cannot reach a
            // foreign Terminal even if the persisted receipt's terminal_id
            // is somehow stale or cross-tenant. (Round-3 Finding 4 closure
            // — F2 scoping above means `$existing` is already same-company,
            // but the explicit predicates below are defense-in-depth and
            // unblock the scanner-blind-spot tracker.)
            $terminalForEcho = Terminal::query()
                ->where('tenant_id', $existing->tenant_id)
                ->where('company_id', $existing->company_id)
                ->where('id', $existing->terminal_id)
                ->first();

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

            // 4. Validate hash chain continuity (production receipts only).
            // Training receipts (T2.7) never enter the fiscal chain, so the
            // chain-continuity check is skipped — the terminal's last_hash and
            // current_sequence are unchanged by training-mode sync. Mirrors the
            // online `ReceiptCreationService` skip-chain-advance branch at
            // ReceiptCreationService.php:605.
            if (! $payload->isTraining) {
                $clientPreviousHash = $payload->previousHash ?? '';
                $terminalLastHash = $terminal->last_hash ?? '';
                if ($clientPreviousHash !== $terminalLastHash) {
                    return SyncReceiptResult::failed(
                        $payload->idempotencyKey,
                        'Hash chain break: client previous_hash does not match terminal last_hash',
                    );
                }
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

                // Round-3 Codex Finding 1 — defense-in-depth: even when the
                // FormRequest validator passes, a programmatic caller (queue
                // retry, backfill) constructing SyncReceiptPayload directly
                // bypasses ScopedExists. We REWRITE the FK columns from the
                // resolved entity ids — a payload UUID that fails scoped
                // resolution is persisted as NULL, never as a raw cross-tenant
                // FK reference.
                $resolvedProductId = null;
                $resolvedCompositeItemId = null;

                if ($productId !== null) {
                    // api.pos-stabilization.025 — scope Product::find by the
                    // anchoring terminal's tenant + company. Cross-tenant
                    // product_id resolves to null and the sellable snapshot
                    // falls back to its 'Unknown Product' default.
                    $product = Product::query()
                        ->where('tenant_id', $terminal->tenant_id)
                        ->where('company_id', $terminal->company_id)
                        ->find($productId);
                    if ($product !== null) {
                        $resolvedProductId = $product->id;
                        $sellableName = $product->name;
                        $sellableCode = $product->sku ?? $product->barcode ?? '';
                        $sellableUnit = $product->unit ?? 'pc';
                        // Normalize tax_rate to 2 decimal places so the vatBreakdown hash is
                        // consistent regardless of how the column is returned by the DB driver
                        // (e.g. 0 vs '0.00' vs '0' for a nullable decimal(5,2) column).
                        $taxRate = CurrencyScale::bcformat($product->tax_rate ?? '0', 2);
                    }
                } elseif ($compositeItemId !== null) {
                    // Round-2 Opus Finding 2 — composite_items has T+C cols.
                    // Scope by anchoring terminal's tenant + company to keep
                    // the Treasury invariant on every service-tier find that
                    // a foreign sellable snapshot cannot leak into the
                    // receipt write. Cross-tenant composite_item_id resolves
                    // to null and falls through to 'Unknown Product' default.
                    $compositeItem = CompositeItem::query()
                        ->where('tenant_id', $terminal->tenant_id)
                        ->where('company_id', $terminal->company_id)
                        ->find($compositeItemId);
                    if ($compositeItem !== null) {
                        $resolvedCompositeItemId = $compositeItem->id;
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
                    // Round-3 Codex Finding 1 — persist the SCOPED-RESOLVED ids
                    // so a foreign payload UUID is stored as NULL, not as a
                    // cross-tenant FK reference.
                    'product_id' => $resolvedCompositeItemId !== null ? null : $resolvedProductId,
                    'composite_item_id' => $resolvedCompositeItemId,
                    // C2 Day 3 — surface the category context the cashier
                    // sold under. Persisted nullable; Menu tenants populate
                    // it post-Day-3, non-Menu tenants and pre-C2 historical
                    // lines do not. Refund flow uses this to rebuild the
                    // composite POSProduct id so the recall matches the
                    // same priced row the cashier originally rang up.
                    'menu_category_id' => isset($lineData['menu_category_id'])
                        ? (string) $lineData['menu_category_id']
                        : null,
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
            //
            //    T2.7 — training receipts do NOT advance the chain and MUST NOT reset
            //    the production sequence counter. The receipt_year column is still
            //    populated from $postedAt for reporting consistency, but
            //    `terminal.current_year` and `terminal.current_sequence` are owned by
            //    the production path only.
            $currentYear = (int) $postedAt->format('Y');
            if (! $payload->isTraining && $terminal->current_year !== $currentYear) {
                $terminal->current_year = $currentYear;
                $terminal->current_sequence = 1;
                $terminal->save();
            }

            // 8. Create receipt.
            //    Production: pending_seal — no inline hash, no terminal advance yet.
            //    ReceiptFinalizationService will compute the hash and advance the chain.
            //    Training (T2.7): inline `Fiscalized` status with a deterministic
            //    `sha256('TRAINING-' || receipt_id)` sentinel hash and
            //    `chain_sequence=null`.
            //
            //    Codex round-1 P1 (2026-05-10): the PostgreSQL CHECK constraint
            //    `chain_sequence IS NULL OR chain_sequence > 0` (migration
            //    `2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php`)
            //    rejects any row with `chain_sequence = 0`. The unique key on
            //    `(terminal_id, receipt_year, chain_sequence)` would also block
            //    a second training receipt for the same terminal/year if both
            //    used a literal `0`. NULL satisfies both — PG treats NULLs as
            //    not-equal in unique constraints, so multiple training rows
            //    coexist; the CHECK exempts NULL.
            //
            //    Note: the online `ReceiptCreationService.php:540` still writes
            //    `chain_sequence = 0` for training receipts. That path also
            //    fails this constraint and needs a follow-up fix to NULL —
            //    OUT OF SCOPE for this PR (T2.7 backend offline-sync slice).
            //    Tracked in the kickoff doc + this PR's body.
            $receiptId = Str::uuid()->toString();
            $isTraining = $payload->isTraining;
            $trainingFiscalHash = $isTraining ? hash('sha256', 'TRAINING-'.$receiptId) : null;

            $receipt = new Receipt([
                'tenant_id' => $terminal->tenant_id,
                'company_id' => $companyId,
                'location_id' => $terminal->location_id,
                'terminal_id' => $terminal->id,
                'receipt_number' => $payload->receiptNumber,
                'receipt_type' => ReceiptType::Sale,
                // Training: NULL (CHECK constraint forbids 0; NULL keeps rows
                // outside the chain in a way that's both valid against the
                // CHECK and accepted by the unique constraint on (terminal_id,
                // receipt_year, chain_sequence)). Production: null until
                // finalizationService sets the production sequence.
                'chain_sequence' => null,
                'receipt_year' => $currentYear,
                // Training receipts are never part of any chain — no previous_hash.
                'previous_hash' => null,
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
                // Training: Fiscalized inline (no pending_seal → finalize transition).
                'fiscal_status' => $isTraining ? FiscalStatus::Fiscalized : FiscalStatus::PendingSeal,
                // Training: inline sentinel hash; production: null until finalize.
                'fiscal_hash' => $trainingFiscalHash,
                'is_training' => $isTraining,
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
                // api.pos-stabilization.026 — scope PaymentMethod::findOrFail
                // by the anchoring terminal's tenant + company. Cross-tenant
                // payment_method_id raises ModelNotFoundException, aborting
                // the sync transaction before any ReceiptPayment row is written.
                $method = PaymentMethod::query()
                    ->where('tenant_id', $terminal->tenant_id)
                    ->where('company_id', $terminal->company_id)
                    ->findOrFail($entry['payment_method_id']);

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
            //
            //     T2.7 — training receipts skip finalize entirely. The fiscal_hash
            //     sentinel was set inline at step 8 (sha256('TRAINING-' || receipt_id))
            //     and the chain is not advanced. Mirrors the online training path's
            //     skip-chain-advance branch at ReceiptCreationService.php:605-608.
            if (! $isTraining) {
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
            } else {
                // Training: the inline sentinel hash IS the receipt's fiscal_hash.
                // No comparison against `payload->offlineFiscalHash` — training
                // receipts don't carry a meaningful client hash (the offline POS
                // either sends a sentinel of its own or whatever value the v3 hash
                // computer would have produced for the no-chain case; the server
                // is the source of truth via the deterministic sha256 above).
                $fiscalHash = $trainingFiscalHash;
            }

            // 13b. B5-fix audit blocker (2026-05-01): for every store_voucher
            //      payment row that was just persisted, invoke
            //      VoucherRedemptionService::redeem so the canonical
            //      redemption lands on the server (voucher_ledger Redeemed
            //      row, voucher balance decrement, status transition, GL
            //      journal). This mirrors what ReceiptPaymentService does on
            //      the online admin POS path and closes the offline → sync
            //      audit blocker.
            //
            //      ORDERING: redemption MUST happen AFTER finalize() so the
            //      v3 fiscal hash is computed BEFORE any voucher_ledger
            //      row exists for this receipt. The offline POS hashes with
            //      `voucher_ledger_entries: []` (the spec's offline
            //      simplification — voucher ledger writes are server-
            //      authored side effects, not inputs to the offline hash).
            //      If we redeemed BEFORE finalize, the V3ReceiptHashComputer
            //      would read the just-written voucher_ledger row and
            //      produce a hash that does not match the offline hash,
            //      breaking the chain on every voucher-bearing receipt.
            //
            //      The redemption call inherits the wrapping transaction; a
            //      VoucherRedemptionException (insufficient balance,
            //      unknown voucher, expired, currency mismatch, terminal
            //      mismatch, duplicate-in-transaction) bubbles to the outer
            //      syncBatch() catch block and is reported as
            //      SyncStatus::Failed — the wrapping transaction rolls
            //      back the receipt + lines + VAT + payments + chain
            //      advance + redemption atomically, so no partial state
            //      lands on disk and the chain does NOT advance.
            //
            //      T2.7 — training receipts NEVER redeem real vouchers. A
            //      training-mode cashier practising voucher tendering must
            //      not decrement a real voucher's balance. The receipt_payments
            //      row still persists with its `instrument_serial` (preserves
            //      the receipt-line shape for reporting), but no
            //      voucher_ledger Redeemed entry is written. The frontend
            //      should gate voucher tenders during training mode; this
            //      backend skip is defense-in-depth.
            if (! $isTraining) {
                foreach ($payload->payments as $entry) {
                    $instrumentTypeValue = $entry['instrument_type'] ?? null;
                    if ($instrumentTypeValue !== 'store_voucher') {
                        continue;
                    }

                    $instrumentSerial = $entry['instrument_serial'] ?? null;
                    if (! is_string($instrumentSerial) || $instrumentSerial === '') {
                        // Defense-in-depth: the B4 guard above already enforces
                        // a non-empty serial when method_code requires it.
                        // This branch is unreachable when the guard ran.
                        throw InstrumentRequiredException::forMethodCode((string) $entry['method_code']);
                    }

                    /** @var numeric-string $appliedAmount */
                    $appliedAmount = (string) $entry['amount'];
                    $this->voucherRedemptionService->redeem(new VoucherRedemptionRequest(
                        voucherCode: $instrumentSerial,
                        appliedAmount: $appliedAmount,
                        currency: (string) $receipt->currency,
                        receiptId: $receipt->id,
                        cashierId: (string) $receipt->cashier_id,
                        terminalId: (string) $receipt->terminal_id,
                        partnerId: null,
                        instrumentKind: PaymentInstrumentKind::StoreVoucher,
                    ));
                }
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
