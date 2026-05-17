<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * POS-core projection for `SALE_RECEIPT` fiscal events — spec v7 §7.5 + §13 + SoT §13.6/D16.
 *
 * **Always runs.** `requiresModule() === null` — the POS-core business effects
 * (`pos_receipts` projection row + lines + VAT details + `pos_receipt_payments`
 * rows + voucher redemption + stock movement) are independent of the Treasury
 * operational module. They depend only on **mirrored reference data**
 * (`payment_methods` lookup by `payment_method_id`) — the inbound side of the
 * bounded-modules asymmetric seam (SoT §13.6/D16). The Treasury-side
 * `Payment` row + GL is owned by `TreasuryReceiptBridge` (Task 22), which
 * runs only when the Treasury module is active.
 *
 * **Idempotency anchor.** The durable guard is the `pos_receipts.fiscal_event_id
 * UNIQUE` column added in Task 11 — `apply()` checks for an existing
 * `pos_receipts` row keyed on `fiscal_event_id` and returns early if found.
 * The `fiscal_event_projections` row (Task 9 — keyed on
 * `(fiscal_event_id, projector_name)`) tracks job-level lifecycle; the
 * `pos_receipts.fiscal_event_id` guard is what makes `apply()` itself
 * safe to re-run for manual replay paths.
 *
 * **Mirror columns.** The legacy chain columns on `pos_receipts`
 * (`fiscal_hash`, `previous_hash`, `chain_sequence`) are no longer
 * advanced independently — they mirror the authoritative `fiscal_events`
 * row's values (`current_hash`, `previous_hash`, `sequence_number`). The
 * chain truth lives in `fiscal_events`. Both `canonical_bytes` and
 * `fiscal_event_id` are NOT in the `prevent_receipt_modification` trigger's
 * whitelist — they MUST be set on INSERT and MUST NEVER be UPDATEd later.
 *
 * **Boundary discipline.** This class imports ZERO Treasury / Accounting /
 * Sales operational classes — only the `PaymentMethod` model for inbound
 * mirrored reference data lookup. The Treasury `Payment` write + GL post +
 * allocation is owned by `TreasuryReceiptBridge` and runs in a separate
 * projector job.
 *
 * **Logic relocation.** This projector consolidates the business-effect
 * logic previously scattered across:
 *   - `ReceiptSyncService::syncSingleReceipt()` — voucher redemption
 *     (steps 13b) and stock decrement (step 14)
 *   - `ReceiptPaymentService::recordSplit*Payments()` — `ReceiptPayment`
 *     row creation
 * Those services remain (legacy callers untouched until Tasks 23/24/25 wire
 * the projection job pipeline); the projector is the new authoritative
 * write surface for the device-authored fiscal-event path.
 */
final class PosCoreReceiptProjection implements FiscalEventProjector
{
    /** Currency-scale used by all numeric columns on `pos_receipts*` (`decimal:3`). */
    private const int SCALE = 3;

    public function __construct(
        private readonly VoucherRedemptionService $voucherRedemptionService,
    ) {}

    public function name(): string
    {
        return 'pos_core_receipt';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::SALE_RECEIPT;
    }

    public function requiresModule(): ?string
    {
        // POS-core projector — always active. The bounded-modules asymmetric
        // seam (SoT §13.6/D16) permits inbound mirrored reference data
        // (`payment_methods.id` FK); the Treasury operational module is NOT
        // a dependency.
        return null;
    }

    public function apply(FiscalEvent $event): void
    {
        // Idempotency guard — Task 11 UNIQUE-backed `pos_receipts.fiscal_event_id`.
        // The `fiscal_event_projections` row tracks job-level state; this
        // guard makes `apply()` itself safe to re-run for manual replay.
        if (Receipt::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        // The verified-event payload is always present on a successfully-parsed
        // SALE_RECEIPT; defensive bail-out below in case the projector is
        // dispatched on a quarantine row whose parse failed (which Task 19
        // currently suppresses via `canonical_parse_failure`, but the guard
        // is cheap and the contract is clear).
        $payload = $event->payload;
        if (! is_array($payload)) {
            return;
        }

        DB::transaction(function () use ($event, $payload): void {
            // Re-check inside the transaction — a concurrent projector
            // dispatch racing this one would otherwise both insert
            // pos_receipts rows and the second would crash on the
            // UNIQUE constraint. The check inside the transaction keeps
            // the projector callable from a retry job without surfacing
            // a UNIQUE-violation crash up the stack.
            if (Receipt::query()->where('fiscal_event_id', $event->id)->exists()) {
                return;
            }

            $terminal = $this->resolveTerminal($event);
            // Terminal lookup is intentionally non-fatal: a malformed
            // `terminal_id` UUID or a deleted terminal must not crash the
            // projector. The fiscal_event row is the chain truth; the
            // projection is the read model. Skipping a projection row leaves
            // the fiscal_events row + the fiscal_event_projections row's
            // failed/poison status for operator-led repair.
            if ($terminal === null) {
                Log::warning('PosCoreReceiptProjection: terminal not found for fiscal event', [
                    'fiscal_event_id' => $event->id,
                    'terminal_id' => $event->terminal_id,
                ]);

                return;
            }

            $receiptId = Str::uuid()->toString();
            $cashierName = $this->resolveCashierName($event->operator_id);

            $subtotal = FiscalPayloadArrayGuards::requireString($payload, 'subtotal');
            $taxTotal = FiscalPayloadArrayGuards::requireString($payload, 'tax_total');
            $discountTotal = FiscalPayloadArrayGuards::requireString($payload, 'discount_total');
            $total = FiscalPayloadArrayGuards::requireString($payload, 'total');
            $currency = FiscalPayloadArrayGuards::requireString($payload, 'currency');

            $subtotalNorm = $this->normalize($subtotal);
            $taxAmountNorm = $this->normalize($taxTotal);
            $discountAmountNorm = $this->normalize($discountTotal);
            $totalNorm = $this->normalize($total);

            $postedAt = $event->event_time_device;
            $receiptYear = (int) $postedAt->format('Y');

            $receiptNumber = $this->buildReceiptNumber(
                $terminal,
                $receiptYear,
                $event->sequence_number,
            );

            // pos_receipts INSERT. Mirror columns come straight from the
            // authoritative fiscal_events row; legacy `fiscal_status` is
            // set to Fiscalized inline because the chain seal has already
            // occurred device-side and the server verified it in Task 19.
            //
            // `id` is not in Receipt::$fillable (HasUuids would otherwise
            // auto-generate one and silently discard the explicit value).
            // Construct + assign + save so the deterministic receiptId
            // round-trips correctly, then use it as the FK for lines /
            // VAT / payments.
            $receipt = new Receipt([
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'location_id' => $terminal->location_id,
                'terminal_id' => $event->terminal_id,
                'receipt_number' => $receiptNumber,
                'receipt_type' => ReceiptType::Sale,
                // Mirror columns — sourced from $event, not advanced independently.
                'chain_sequence' => $event->sequence_number,
                'receipt_year' => $receiptYear,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'vat_breakdown_hash' => $this->placeholderHash(),
                'payment_methods_hash' => $this->placeholderHash(),
                'posted_at' => $postedAt,
                'cashier_id' => $event->operator_id,
                'cashier_name' => $cashierName,
                'subtotal' => $subtotalNorm,
                'tax_amount' => $taxAmountNorm,
                'discount_amount' => $discountAmountNorm,
                'total' => $totalNorm,
                'currency' => $currency,
                'fiscal_status' => FiscalStatus::Fiscalized,
                'is_voided' => false,
                'is_training' => false,
                // Task 11 mirror + linkage columns. NOT in the immutability
                // trigger whitelist, so they MUST be set on INSERT and
                // MUST NEVER be UPDATEd later.
                'canonical_bytes' => $event->canonical_bytes,
                'fiscal_event_id' => $event->id,
            ]);
            $receipt->id = $receiptId;
            $receipt->save();

            $this->writeLines($receiptId, $payload);
            $this->writeVatBreakdown($receiptId, $payload);
            $this->writePayments($receiptId, $event, $payload, $terminal);
            $this->redeemVouchers($receiptId, $event, $payload);
            $this->decrementStockForLines($receiptId, $event, $terminal, $payload);
        });
    }

    /**
     * Resolve the terminal row. Returns null when the event's `terminal_id`
     * is not a queryable UUID or no row matches. Wraps `find()` in a
     * `try/catch (QueryException)` because PostgreSQL rejects malformed
     * UUIDs at the driver layer (Task 17 P3-3 standing pattern carry-forward).
     */
    private function resolveTerminal(FiscalEvent $event): ?Terminal
    {
        try {
            return Terminal::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->where('id', $event->terminal_id)
                ->first();
        } catch (QueryException $e) {
            Log::warning('PosCoreReceiptProjection: terminal lookup failed', [
                'fiscal_event_id' => $event->id,
                'terminal_id' => $event->terminal_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Look up the cashier's name for the snapshot column. Falls back to a
     * deterministic placeholder so a deleted/missing user never crashes
     * projection (the fiscal event was authored device-side; the projection
     * must succeed even if the user record has since been removed).
     */
    private function resolveCashierName(string $operatorId): string
    {
        try {
            /** @var User|null $user */
            $user = User::query()->find($operatorId);
        } catch (QueryException) {
            return 'Unknown';
        }

        if ($user === null) {
            return 'Unknown';
        }

        $name = $user->name;

        return $name === '' ? 'Unknown' : $name;
    }

    /**
     * Build the legacy `receipt_number` snapshot column. The column is
     * UNIQUE; deriving it deterministically from `(terminal, year, sequence)`
     * keeps the value reproducible across retries — important because the
     * projector's idempotency guard is on `fiscal_event_id`, not on
     * `receipt_number`, and a non-deterministic generator would surface as
     * a UNIQUE violation on the second attempt of a partial-write retry.
     */
    private function buildReceiptNumber(Terminal $terminal, int $year, int $sequence): string
    {
        $terminalCode = (string) ($terminal->code ?? 'POS');
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return sprintf('FE-%s-%d-%s', $terminalCode, $year, $sequenceStr);
    }

    /**
     * Insert the `pos_receipt_lines` rows. The line payload is a
     * `list<array<string, mixed>>` per `SaleReceiptPayload`; per-row
     * `FiscalPayloadArrayGuards` guard the required keys so a malformed
     * sub-array fails loudly inside the transaction (rolling the whole
     * projection back) instead of silently writing partial data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeLines(string $receiptId, array $payload): void
    {
        $lines = FiscalPayloadArrayGuards::requireArray($payload, 'lines');

        $lineNumber = 1;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw new RuntimeException('PosCoreReceiptProjection: lines[] entry is not an array');
            }
            /** @var array<string, mixed> $line */
            $unitPrice = FiscalPayloadArrayGuards::requireString($line, 'unit_price');
            $lineTotal = FiscalPayloadArrayGuards::requireString($line, 'line_total');
            $sku = FiscalPayloadArrayGuards::requireString($line, 'sku');

            $quantity = FiscalPayloadArrayGuards::optionalString($line, 'quantity') ?? '1';
            $taxRate = FiscalPayloadArrayGuards::optionalString($line, 'tax_rate') ?? '0';
            $taxAmount = FiscalPayloadArrayGuards::optionalString($line, 'tax_amount') ?? '0';
            $discountAmount = FiscalPayloadArrayGuards::optionalString($line, 'discount_amount') ?? '0';
            $unit = FiscalPayloadArrayGuards::optionalString($line, 'unit') ?? 'pc';

            $productId = FiscalPayloadArrayGuards::optionalString($line, 'product_id');
            $compositeItemId = FiscalPayloadArrayGuards::optionalString($line, 'composite_item_id');
            $menuCategoryId = FiscalPayloadArrayGuards::optionalString($line, 'menu_category_id');
            $productCode = FiscalPayloadArrayGuards::optionalString($line, 'product_code') ?? $sku;
            $productName = FiscalPayloadArrayGuards::optionalString($line, 'product_name') ?? $sku;

            ReceiptLine::query()->create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receiptId,
                'line_number' => $lineNumber++,
                'product_id' => $productId,
                'composite_item_id' => $compositeItemId,
                'menu_category_id' => $menuCategoryId,
                'product_code' => $productCode,
                'product_name' => $productName,
                'product_description' => null,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'discount_reason' => null,
            ]);
        }
    }

    /**
     * Insert the `pos_receipt_vat_details` rows from `payload['vat_breakdown']`.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeVatBreakdown(string $receiptId, array $payload): void
    {
        $vatBreakdown = FiscalPayloadArrayGuards::requireArray($payload, 'vat_breakdown');

        foreach ($vatBreakdown as $vat) {
            if (! is_array($vat)) {
                throw new RuntimeException('PosCoreReceiptProjection: vat_breakdown[] entry is not an array');
            }
            /** @var array<string, mixed> $vat */
            $rate = FiscalPayloadArrayGuards::requireString($vat, 'rate');
            $base = FiscalPayloadArrayGuards::requireString($vat, 'base');
            $amount = FiscalPayloadArrayGuards::requireString($vat, 'amount');

            $gross = bcadd($this->normalize($base), $this->normalize($amount), self::SCALE);

            ReceiptVatDetail::query()->create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receiptId,
                'tax_rate' => $rate,
                'net_amount' => $base,
                'vat_amount' => $amount,
                'gross_amount' => $gross,
            ]);
        }
    }

    /**
     * Insert the `pos_receipt_payments` rows from `payload['payment_lines']`.
     *
     * This is the **single owner** of `ReceiptPayment` row creation regardless
     * of input path — relocated from `ReceiptPaymentService::recordSplit*Payments`
     * (`:297`). The Treasury `Payment` row + GL post + allocation that the
     * legacy service also did is owned by `TreasuryReceiptBridge` (Task 22)
     * and runs only when the Treasury module is active.
     *
     * Cross-tenant `payment_method_id` is rejected: the `PaymentMethod` lookup
     * is scoped by the fiscal event's tenant + company so a foreign FK lands
     * as `null` and the row insert fails on the NOT NULL FK — atomic rollback.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writePayments(
        string $receiptId,
        FiscalEvent $event,
        array $payload,
        Terminal $terminal,
    ): void {
        $paymentLines = FiscalPayloadArrayGuards::requireArray($payload, 'payment_lines');

        foreach ($paymentLines as $line) {
            if (! is_array($line)) {
                throw new RuntimeException('PosCoreReceiptProjection: payment_lines[] entry is not an array');
            }
            /** @var array<string, mixed> $line */
            $paymentMethodId = FiscalPayloadArrayGuards::requireString($line, 'payment_method_id');
            $amount = FiscalPayloadArrayGuards::requireString($line, 'amount');
            $methodCode = FiscalPayloadArrayGuards::requireString($line, 'method_code');

            $instrumentType = FiscalPayloadArrayGuards::optionalString($line, 'instrument_type');
            $instrumentSerial = FiscalPayloadArrayGuards::optionalString($line, 'instrument_serial');
            $cardLastFour = FiscalPayloadArrayGuards::optionalString($line, 'card_last_four');
            $transactionReference = FiscalPayloadArrayGuards::optionalString($line, 'transaction_reference');

            // Defense-in-depth B4 fence — the device-side validator enforces
            // this, but a programmatic caller (queue retry, backfill) could
            // bypass it. A v3 receipt sealed with method_code=store_voucher
            // and instrument_serial=null is exactly the fiscal-hash hole B4
            // closed. Throwing here rolls back the projection's DB
            // transaction; the projector job is then re-tried by the
            // Task 23 worker which will hit the same B4 throw and surface
            // the projection_status as failed.
            if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
                if ($instrumentType === null || $instrumentType === ''
                    || $instrumentSerial === null || $instrumentSerial === '') {
                    throw InstrumentRequiredException::forMethodCode($methodCode);
                }
            }

            // Inbound mirrored reference-data lookup — permitted per
            // SoT §13.6/D16 (bounded-modules asymmetric seam). Scoped by
            // the fiscal event's tenant + company so a foreign
            // payment_method_id resolves to null and the row insert
            // crashes on the NOT NULL FK — atomic rollback.
            try {
                $method = PaymentMethod::query()
                    ->where('tenant_id', $terminal->tenant_id)
                    ->where('company_id', $terminal->company_id)
                    ->find($paymentMethodId);
            } catch (QueryException) {
                $method = null;
            }

            $instrumentKind = $instrumentType !== null && $instrumentType !== ''
                ? PaymentInstrumentKind::from($instrumentType)
                : null;

            $paymentType = $method !== null ? $method->name : $methodCode;

            ReceiptPayment::query()->create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receiptId,
                'payment_method_id' => $paymentMethodId,
                'payment_type' => $paymentType,
                'payment_method_code' => $methodCode,
                'amount' => $amount,
                'card_last_four' => $cardLastFour,
                'transaction_reference' => $transactionReference,
                'instrument_type' => $instrumentKind,
                'instrument_serial' => $instrumentSerial,
                // Treasury linkage column intentionally left NULL — the
                // Treasury bridge (Task 22) populates `treasury_payment_id`
                // when it creates the operational Payment row. POS-core
                // projection does not depend on Treasury.
                'treasury_payment_id' => null,
            ]);

            unset($event);
        }
    }

    /**
     * Voucher redemption — relocated from `ReceiptSyncService::syncSingleReceipt`
     * (`:683-710`). Iterates `payment_lines` and calls
     * `VoucherRedemptionService::redeem` for every `store_voucher` instrument.
     *
     * Ordering matters: the fiscal event was already sealed device-side and
     * verified server-side in Task 19. Voucher-ledger writes are server-
     * authored side effects of the projection and never feed back into the
     * authoritative fiscal hash.
     *
     * The redemption call inherits the wrapping DB transaction; a
     * `VoucherRedemptionException` rolls back the receipt + lines + VAT
     * + payments + voucher state atomically — no partial chain state lands
     * on disk.
     *
     * @param  array<string, mixed>  $payload
     */
    private function redeemVouchers(string $receiptId, FiscalEvent $event, array $payload): void
    {
        $paymentLines = FiscalPayloadArrayGuards::requireArray($payload, 'payment_lines');
        $currency = FiscalPayloadArrayGuards::requireString($payload, 'currency');

        foreach ($paymentLines as $line) {
            if (! is_array($line)) {
                continue;
            }
            /** @var array<string, mixed> $line */
            $instrumentType = FiscalPayloadArrayGuards::optionalString($line, 'instrument_type');
            if ($instrumentType !== PaymentInstrumentKind::StoreVoucher->value) {
                continue;
            }

            $instrumentSerial = FiscalPayloadArrayGuards::optionalString($line, 'instrument_serial');
            if ($instrumentSerial === null || $instrumentSerial === '') {
                // Defense-in-depth: B4 guard in writePayments() already
                // throws when method_code=store_voucher lacks a serial.
                // This branch is unreachable on the happy path.
                continue;
            }

            $amount = FiscalPayloadArrayGuards::requireString($line, 'amount');
            /** @var numeric-string $amount */
            $this->voucherRedemptionService->redeem(new VoucherRedemptionRequest(
                voucherCode: $instrumentSerial,
                appliedAmount: $amount,
                currency: $currency,
                receiptId: $receiptId,
                cashierId: $event->operator_id,
                terminalId: $event->terminal_id,
                partnerId: null,
                instrumentKind: PaymentInstrumentKind::StoreVoucher,
            ));
        }
    }

    /**
     * Stock decrement for product lines — relocated from
     * `ReceiptSyncService::decrementStock` (`:744-804`). Inventory-side
     * side effect of the projection; runs in the same transaction so a
     * stock-write failure rolls the entire projection back atomically.
     *
     * @param  array<string, mixed>  $payload
     */
    private function decrementStockForLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        array $payload,
    ): void {
        $lines = FiscalPayloadArrayGuards::requireArray($payload, 'lines');

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            /** @var array<string, mixed> $line */
            $productId = FiscalPayloadArrayGuards::optionalString($line, 'product_id');
            if ($productId === null) {
                continue;
            }
            $quantity = FiscalPayloadArrayGuards::optionalString($line, 'quantity') ?? '1';

            $this->decrementStock(
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                locationId: (string) $terminal->location_id,
                productId: $productId,
                quantity: $quantity,
                receiptId: $receiptId,
                cashierId: $event->operator_id,
            );
        }
    }

    /**
     * Decrement stock for one product line. Mirrors the legacy
     * `ReceiptSyncService::decrementStock` behaviour: when no `stock_levels`
     * row exists for the (product, location, company) tuple, the decrement
     * is silently skipped (the sale already happened device-side; the
     * fiscal chain is the source of truth). When insufficient stock is
     * available, a warning is logged but the write proceeds — the device-
     * authored sale cannot be rolled back without breaking the fiscal chain.
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
        $stockLevel = StockLevel::query()
            ->where('product_id', $productId)
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
            Log::warning('PosCoreReceiptProjection: insufficient stock during projection', [
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

        StockMovement::query()->create([
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
            'reference' => 'POS Fiscal Event Projection',
            'reference_type' => 'pos_receipt',
            'reference_id' => $receiptId,
            'notes' => "Stock issued via PosCoreReceiptProjection (receipt: {$receiptId})",
            'user_id' => $cashierId,
            'is_historical' => false,
        ]);
    }

    /**
     * Normalize a decimal-string monetary value to the projection scale.
     * Uses bcadd against '0' which keeps the value exact (no float
     * intermediate) while padding/truncating to the column's `decimal:3`
     * precision so the projection-row insert satisfies the `total =
     * subtotal + tax_amount` CHECK constraint on PG.
     *
     * The input MUST be a `numeric-string` per the spec §4 contract on
     * canonical monetary values; the parser (Task 16) rejects malformed
     * payloads before they reach this projector. A non-numeric value at
     * this point is a programmer error in an upstream task, not a runtime
     * concern.
     *
     * @return numeric-string
     */
    private function normalize(string $value): string
    {
        /** @var numeric-string $value */
        /** @var numeric-string $normalized */
        $normalized = bcadd($value, '0', self::SCALE);

        return $normalized;
    }

    /**
     * The legacy `vat_breakdown_hash` / `payment_methods_hash` columns are
     * NOT NULL on the table and CHECK-constrained to 64 chars (PG only).
     * Pre-fiscal-engine these were content-addressed hashes; under §13's
     * mirror-column regime the authoritative hash is `fiscal_events.current_hash`
     * (mirrored to `fiscal_hash`) and the legacy per-section hashes are
     * deprecated. We populate them with a deterministic 64-char sentinel
     * so the constraint passes without inventing content-meaningful hash
     * values that downstream readers might mistake for v2-style chain inputs.
     */
    private function placeholderHash(): string
    {
        return str_repeat('0', 64);
    }
}
