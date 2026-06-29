<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\OriginalReceiptUnresolvableException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
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
 * operational module. They depend only on **mirrored reference data** — the
 * `PaymentMethodResolver` seam (Shared/Contracts/Fiscal) which resolves
 * `payment_methods.id` by `(tenant_id, method_code)`. The Treasury-side
 * `Payment` row + GL is owned by `TreasuryReceiptBridge` (Task 22), which
 * runs only when the Treasury module is active.
 *
 * **Pass 2A.PHP.2 — 27-key canonical migration (synthesis v5 §8.B).**
 * Reads `fiscal_events.payload` through `CanonicalPayloadReader` for the
 * fiscal-event-backed path. The canonical SALE_RECEIPT payload no longer
 * carries `payment_method_id` per-payment — the projector resolves the
 * tenant-scoped FK via `PaymentMethodResolver::resolveByCode($tenantId,
 * $payment->methodCode)`. A null return triggers fail-closed RuntimeException
 * (same security stance as Task 21 R2 Opus F3, now expressed via the
 * Shared/Contracts seam instead of a direct Treasury Eloquent traversal).
 *
 * **D16 sale-time snapshot invariant.** The projector reads buyer data
 * EXCLUSIVELY from `fiscal_events.payload.buyer` (sale-time snapshot).
 * NEVER traverses runtime customer / contact / B2B tables; the
 * `PosCoreReceiptProjectionD16Test` grep guard pins this invariant by
 * forbidding both module imports AND Eloquent static-call surfaces.
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
 * **Canonical-only fields (synthesis v5 §3).** Several per-line / per-payment
 * / per-vat-row fields exist only on `fiscal_events.payload` and are NOT
 * projected to columns:
 *   - `line_items[].gtin` (DSFinV-K future)
 *   - `line_items[].tax_category_code` + `vat_breakdown[].tax_category_code`
 *     (unified KSA BT-151 / IT Natura axis)
 *   - `line_items[].non_collected_subtype` (IT future)
 *   - `payments[].foreign_currency_amount` + `foreign_currency_code` (FX legs)
 *   - `original_receipt_reference` (refund/void linkage — readable via
 *     `CanonicalPayloadReader`)
 *   - `seller` block (multi-country fiscal registration data)
 *   - `buyer.address` / `buyer.tax_number` / `buyer.codice_fiscale` etc.
 *
 * The Nf525DataProvider reads these from `CanonicalPayloadReader::forSaleReceipt()`
 * for the fiscal-event-backed export path.
 *
 * **Logic relocation (spec v7 §14 + plan §1635).** This projector
 * consolidates the business-effect logic previously scattered across the
 * retired receipt-sync path and `ReceiptPaymentService::processReceiptPayments()`.
 */
final class PosCoreReceiptProjection implements FiscalEventProjector
{
    /**
     * Currency-scale used for projector-internal normalization. Matches the
     * `Receipt::$casts` claim (`decimal:3`) for the monetary fields
     * `subtotal` / `tax_amount` / `discount_amount` / `total`.
     */
    private const int SCALE = 3;

    /**
     * **Pass 2A.PHP.2 R2 (Codex P2-4 deferral).** The `PaymentMethodResolver`
     * binding is registered by `TreasuryServiceProvider`. If a deployment
     * excludes the Treasury module entirely (theoretical POS-only minimal
     * profile), this constructor's container resolution would fail with
     * `BindingResolutionException`. The current monorepo always loads
     * Treasury alongside POS, so the risk is dormant. The Phase 1.5 roadmap
     * tracks the fallback binding (POS-side default
     * `EloquentPaymentMethodResolver` registered by `PosServiceProvider`
     * when the Treasury provider is not loaded) for POS-only deployments.
     */
    public function __construct(
        private readonly VoucherRedemptionService $voucherRedemptionService,
        private readonly ReceiptHashService $receiptHashService,
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly PaymentMethodResolver $paymentMethodResolver,
        private readonly LoyaltyEarningContract $loyaltyEarning,
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
        return null;
    }

    public function priority(): int
    {
        return 50;
    }

    public function apply(FiscalEvent $event): void
    {
        // Fast-path idempotency probe.
        if (Receipt::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        // The verified-event payload is always present on a successfully-parsed
        // SALE_RECEIPT; defensive bail-out below in case the projector is
        // dispatched on a quarantine row whose parse failed (Task 19
        // currently suppresses via `canonical_parse_failure`).
        if (! is_array($event->payload)) {
            return;
        }

        // Pass 2A.PHP.2 — typed canonical view over the 27-key payload.
        // CanonicalPayloadReader::forSaleReceipt throws if the payload is
        // structurally absent / wrong-shaped (defense-in-depth — should
        // not fire on a verified row).
        $view = $this->canonicalReader->forSaleReceipt($event);
        $payload = $view->payload;

        DB::transaction(function () use ($event, $view, $payload): void {
            $terminal = $this->resolveTerminal($event);
            if ($terminal === null) {
                Log::warning('PosCoreReceiptProjection: terminal not found for fiscal event', [
                    'fiscal_event_id' => $event->id,
                    'terminal_id' => $event->terminal_id,
                ]);

                return;
            }

            $receiptId = Str::uuid()->toString();
            $cashierName = $this->resolveCashierName($event->operator_id);

            $subtotalNorm = $this->normalize($payload->subtotal);
            $vatTotalNorm = $this->normalize($payload->vatTotal);
            $discountAmountNorm = $this->normalize($payload->transactionDiscountAmount);
            $totalNorm = $this->normalize($payload->total);

            $postedAt = $event->event_time_device;
            $receiptYear = (int) $postedAt->format('Y');

            $receiptNumber = $this->buildReceiptNumber(
                $terminal,
                $receiptYear,
                $event->sequence_number,
            );

            $vatBreakdownHash = $this->computeVatBreakdownHash($view);
            $paymentMethodsHash = $this->computePaymentMethodsHash($view, $event);

            // Refund linkage — original_receipt_id (existing column);
            // populated when invoice_type_code='REFUND'|'VOID' AND
            // original_receipt_reference is present. We look up the local
            // pos_receipts.id whose fiscal_event_id matches the canonical
            // `original_receipt_reference.fiscal_event_id`.
            $originalReceiptId = $this->resolveOriginalReceiptId($view);

            // **Pass 2A.PHP.2 R2 — Codex BLOCKER-2 closure.** For
            // REFUND/VOID, the original receipt MUST be locally resolvable
            // before the projection can apply. Round-1 silently fell
            // through to `ReceiptType::Sale` here, and `Nf525DataProvider::mapSaleReceipt`
            // then exported the row as a sale — a fiscal-compliance
            // violation (NF525 §11.2-§11.4 + spec v7 §14 require voided
            // and return receipts to surface as the correct movement
            // type). The validator already enforces `invoice_type_code in
            // {REFUND,VOID} ⇒ original_receipt_reference is required`
            // (FiscalPayloadConstraintValidator §0 lines 533-568), so the
            // ONLY way `original_receipt_reference` is non-null but
            // `$originalReceiptId` is null is if the original SALE_RECEIPT
            // has not yet projected locally — a retryable dependency-
            // missing case. Throw `OriginalReceiptUnresolvableException`
            // (extends `ProjectionDependencyMissingException`); the job's
            // fail-closed `catch (Throwable)` advances attempts + Horizon
            // retries with backoff. The wrapping `DB::transaction` rolls
            // back atomically — no pos_receipts row, no lines, no payments.
            $this->assertOriginalReceiptResolvableForRefundOrVoid(
                event: $event,
                view: $view,
                originalReceiptId: $originalReceiptId,
            );

            // Receipt type — REFUND/VOID with original_receipt_reference
            // map to ReceiptType::Return. SALE/TRAINING/REFUND-without-ref
            // stay Sale. invoice_type_code is the authoritative canonical
            // axis stored in pos_receipts.invoice_type_code.
            $receiptTypeEnum = $this->resolveReceiptType($payload->invoiceTypeCode, $originalReceiptId);

            // Buyer block snapshot — D16 invariant: read ONLY from the
            // parsed payload. NO live customer/contact/B2B lookup.
            $buyer = $view->buyer;
            $customerName = $buyer?->name;
            $partnerId = $buyer?->customerId;
            $contactId = $buyer?->contactId;
            $customerIdentifier = $buyer?->taxNumber;

            $row = [
                'id' => $receiptId,
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'location_id' => (string) $terminal->location_id,
                'terminal_id' => $event->terminal_id,
                'receipt_number' => $receiptNumber,
                'receipt_type' => $receiptTypeEnum->value,
                'chain_sequence' => $event->sequence_number,
                'receipt_year' => $receiptYear,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'vat_breakdown_hash' => $vatBreakdownHash,
                'payment_methods_hash' => $paymentMethodsHash,
                'posted_at' => $postedAt->format('Y-m-d H:i:s'),
                'cashier_id' => $event->operator_id,
                'cashier_name' => $cashierName,
                'subtotal' => $subtotalNorm,
                'tax_amount' => $vatTotalNorm,
                'discount_amount' => $discountAmountNorm,
                'discount_reason' => $payload->transactionDiscountReason,
                'total' => $totalNorm,
                'currency' => $payload->currencyCode,
                'consumption_mode' => $payload->consumptionMode,
                'table_id' => $payload->tableId,
                'customer_name' => $customerName,
                'customer_identifier' => $customerIdentifier,
                'partner_id' => $partnerId,
                'contact_id' => $contactId,
                'fiscal_status' => FiscalStatus::Fiscalized->value,
                'is_voided' => false,
                'is_training' => $payload->trainingFlag,
                'notes' => $payload->notes,
                // Refund linkage — pos_receipts column exists for the legacy
                // Return path. New canonical refunds populate this when the
                // original was projected locally.
                'original_receipt_id' => $originalReceiptId,
                'canonical_bytes' => $event->canonical_bytes,
                'fiscal_event_id' => $event->id,
                // Pass 2A.PHP.1 (synthesis v5 §3) — projector-consumed columns
                // from the 27-key canonical payload.
                'invoice_type_code' => $payload->invoiceTypeCode,
                'training_flag' => $payload->trainingFlag,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // pos_receipts has a CHECK constraint requiring a return-type
            // row to ALSO carry a non-null return_reason (`pos_receipts_return_logic`).
            // The legacy `return_reason` column is a strict enum
            // (`ReturnReason`); the canonical `original_receipt_reference.refund_reason`
            // is free-text. We write `ReturnReason::Other->value` as the
            // safe enum-compatible fallback so the legacy column hydrates
            // cleanly through the Eloquent cast; the canonical free-text
            // refund_reason remains authoritative inside
            // `fiscal_events.payload.original_receipt_reference` (readable
            // via CanonicalPayloadReader).
            if ($receiptTypeEnum === ReceiptType::Return) {
                $row['return_reason'] = ReturnReason::Other->value;
            }

            $insertedId = $this->insertReceiptOnConflictDoNothing($row);

            if ($insertedId === null) {
                // A concurrent projector dispatch won the race; idempotent no-op.
                return;
            }

            $this->writeLines($receiptId, $event, $view);
            $this->writeVatBreakdown($receiptId, $view);
            $this->writePayments($receiptId, $event, $view);
            $this->redeemVouchers($receiptId, $event, $view);
            $this->earnLoyaltyPoints($receiptId, $event, $view, $payload, $receiptTypeEnum, $totalNorm);
            $this->decrementStockForLines($receiptId, $event, $terminal, $view, $receiptTypeEnum);
        });
    }

    /**
     * Atomic `INSERT INTO pos_receipts (...) VALUES (...) ON CONFLICT ...
     * DO NOTHING RETURNING id` — Task 19 standing pattern.
     *
     * @param  array<string, mixed>  $row
     */
    private function insertReceiptOnConflictDoNothing(array $row): ?string
    {
        $driver = DB::connection()->getDriverName();
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns));
        $bindings = array_values($row);

        if ($driver === 'pgsql') {
            $sql = sprintf(
                'INSERT INTO "pos_receipts" (%s) VALUES (%s) '.
                'ON CONFLICT ON CONSTRAINT pos_receipts_fiscal_event_id_unique '.
                'DO NOTHING RETURNING id',
                $columnList,
                $placeholders,
            );
        } else {
            $sql = sprintf(
                'INSERT INTO "pos_receipts" (%s) VALUES (%s) '.
                'ON CONFLICT (fiscal_event_id) '.
                'DO NOTHING RETURNING id',
                $columnList,
                $placeholders,
            );
        }

        $rows = DB::select($sql, $bindings);

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $id = is_object($first) && property_exists($first, 'id') ? $first->id : null;

        return is_string($id) ? $id : (is_scalar($id) ? (string) $id : null);
    }

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
     * Look up the cashier's name for the snapshot column. NOTE: this is a
     * `users` table lookup, NOT a customer/contact/B2B lookup — the
     * cashier is the device operator, not the buyer. D16 only forbids
     * customer-side runtime traversal; `User::find` for the snapshot
     * column is unchanged from PHP.1 baseline.
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

    private function buildReceiptNumber(Terminal $terminal, int $year, int $sequence): string
    {
        $terminalCode = (string) ($terminal->code ?? 'POS');
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);

        return sprintf('FE-%s-%d-%s', $terminalCode, $year, $sequenceStr);
    }

    /**
     * Map `invoice_type_code` + presence of `original_receipt_reference` to
     * the legacy `ReceiptType` enum:
     *   - SALE / TRAINING → Sale
     *   - REFUND / VOID with $originalReceiptId resolved → Return
     *
     * **Pass 2A.PHP.2 R2 (Codex BLOCKER-2).** REFUND / VOID with an
     * unresolvable `original_receipt_id` no longer falls through to Sale.
     * The fail-closed gate lives upstream in
     * `assertOriginalReceiptResolvableForRefundOrVoid()` — by the time this
     * method runs, REFUND/VOID is guaranteed to have a resolved local
     * original. Any path here is a programmer error and is treated as a
     * hard fault.
     */
    private function resolveReceiptType(string $invoiceTypeCode, ?string $originalReceiptId): ReceiptType
    {
        if (($invoiceTypeCode === 'REFUND' || $invoiceTypeCode === 'VOID') && $originalReceiptId !== null) {
            return ReceiptType::Return;
        }

        return ReceiptType::Sale;
    }

    /**
     * Fail-loud guard for the REFUND/VOID receipt-type compliance gate
     * (Pass 2A.PHP.2 R2 — Codex BLOCKER-2). When `invoice_type_code` is
     * REFUND or VOID, the canonical payload MUST carry an
     * `original_receipt_reference` (enforced upstream by
     * `FiscalPayloadConstraintValidator` §0 lines 533-568). The projector
     * MUST resolve that reference to a local `pos_receipts` row before
     * writing — otherwise the row would project with
     * `receipt_type = 'sale'`, downgrading the NF525 export movement type
     * and falsifying the audit trail.
     *
     * Throws `OriginalReceiptUnresolvableException` (extends
     * `ProjectionDependencyMissingException`) so the Task 23 retry contract
     * applies: the job catches `Throwable`, advances attempts, and Horizon
     * retries with backoff. When the original SALE_RECEIPT projection
     * commits (sync-from-device, sibling job), the next retry resolves and
     * the REFUND/VOID lands as `ReceiptType::Return`.
     */
    private function assertOriginalReceiptResolvableForRefundOrVoid(
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        ?string $originalReceiptId,
    ): void {
        $invoiceTypeCode = $view->payload->invoiceTypeCode;
        if ($invoiceTypeCode !== 'REFUND' && $invoiceTypeCode !== 'VOID') {
            return;
        }

        if ($originalReceiptId !== null) {
            return;
        }

        // `original_receipt_reference` is required by the validator when
        // invoice_type_code in {REFUND, VOID}; guard against parser drift.
        $ref = $view->originalReceiptReference;
        if ($ref === null) {
            // Validator guarantees non-null here; treat as hard programmer
            // error rather than retryable.
            throw new RuntimeException(sprintf(
                'PosCoreReceiptProjection: invariant violation — '.
                'invoice_type_code=%s with null original_receipt_reference '.
                'should have been blocked by FiscalPayloadConstraintValidator '.
                '(fiscal_event_id=%s, tenant_id=%s)',
                $invoiceTypeCode,
                $event->id,
                $event->tenant_id,
            ));
        }

        throw new OriginalReceiptUnresolvableException(
            projectorName: $this->name(),
            fiscalEventId: $event->id,
            eventType: $invoiceTypeCode,
            upstreamFiscalEventId: $ref->fiscalEventId,
            originalReceiptUuid: $ref->originalReceiptUuid,
            tenantId: $event->tenant_id,
        );
    }

    /**
     * Resolve the local `pos_receipts.id` for the refund's original receipt.
     * Lookup walks `pos_receipts.fiscal_event_id` (the idempotency anchor)
     * for the canonical `original_receipt_reference.fiscal_event_id`.
     *
     * Returns null when the original hasn't been projected locally — e.g.
     * cross-terminal refund or refund-against-legacy-receipt. The canonical
     * `original_receipt_reference` remains the authoritative link (readable
     * via CanonicalPayloadReader); the legacy `original_receipt_id` column
     * is best-effort denormalization for legacy-column readers (NF525 export
     * map, partial-refund report).
     */
    private function resolveOriginalReceiptId(SaleReceiptCanonicalView $view): ?string
    {
        $ref = $view->originalReceiptReference;
        if ($ref === null) {
            return null;
        }

        try {
            $original = Receipt::query()
                ->where('fiscal_event_id', $ref->fiscalEventId)
                ->first();
        } catch (QueryException) {
            return null;
        }

        if ($original === null) {
            return null;
        }

        return (string) $original->id;
    }

    /**
     * Insert the `pos_receipt_lines` rows from the canonical view.
     *
     * Canonical line fields written to columns:
     *   - sku / product_id / product_code (mirror sku) / product_name (name)
     *   - quantity / unit_price / line_total (line_subtotal)
     *   - tax_rate (vat_rate) / tax_amount (line_vat)
     *   - discount_amount (line_discount_amount) / discount_reason (line_discount_reason)
     *
     * Canonical-only fields NOT projected to columns (readable via
     * CanonicalPayloadReader):
     *   - gtin (DSFinV-K future)
     *   - tax_category_code (unified KSA/IT axis)
     *   - non_collected_subtype (IT future)
     *
     * **T2 variant_id gap — CLOSED by SaleReceiptV2 (M4).** Since
     * event_version=2 the canonical `LineItemDTO` carries the variant
     * identity (`variant_id`/`variant_name`/`variant_sku`, null for
     * non-variant lines and for all v1 events). The projection resolves the
     * `pos_receipt_lines.variant_id` FK tenant-scoped (same stance as
     * `resolveProductFk` — Codex BLOCKER-1) and anchored to the resolved
     * product FK, honouring the table CHECK
     * `variant_id IS NULL OR product_id IS NOT NULL`. The sealed canonical
     * payload remains authoritative when the FK does not resolve.
     */
    private function writeLines(string $receiptId, FiscalEvent $event, SaleReceiptCanonicalView $view): void
    {
        $lineNumber = 1;
        foreach ($view->lineItems as $line) {
            // pos_receipt_lines.product_id is a foreign key to `products`
            // with `nullable()->restrictOnDelete()`. The canonical
            // `line_items[].product_id` is the audit-stable identifier
            // (sealed snapshot), not necessarily a current `products.id`
            // — e.g. ad-hoc service lines, refund-against-deleted-product,
            // or test fixtures without a matching product row. Resolve
            // the FK by Str::isUuid + tenant-scoped products lookup; if no
            // row exists in the event's tenant, write null to the column
            // (the canonical product_id remains authoritative inside the
            // sealed payload).
            //
            // **Pass 2A.PHP.2 R2 — Codex BLOCKER-1 closure.** The lookup
            // MUST scope by `tenant_id` to prevent cross-tenant FK binding.
            // Same security stance as Task 21 R2 Opus F3 closure for
            // `payment_method_id`. A `product_id` UUID from tenant A that
            // collides with a row in tenant B MUST NOT bind here — the
            // resolver returns null and the column is written as null
            // (sealed snapshot in the canonical payload remains
            // authoritative).
            $productFk = $this->resolveProductFk($event->tenant_id, $line->productId);
            $variantFk = $productFk !== null && $line->variantId !== null
                ? $this->resolveVariantFk($event->tenant_id, $productFk, $line->variantId)
                : null;

            ReceiptLine::query()->create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receiptId,
                'line_number' => $lineNumber++,
                'product_id' => $productFk,
                'variant_id' => $variantFk,
                'composite_item_id' => null,
                'menu_category_id' => null,
                'product_code' => $line->sku,
                'product_name' => $line->name,
                'product_description' => null,
                'quantity' => $line->quantity,
                'unit' => 'pc',
                'unit_price' => $line->unitPrice,
                'line_total' => $line->lineSubtotal,
                'tax_rate' => $line->vatRate,
                'tax_amount' => $line->lineVat,
                'discount_amount' => $line->lineDiscountAmount,
                'discount_reason' => $line->lineDiscountReason,
            ]);
        }
    }

    /**
     * Resolve the local `products.id` FK for the canonical `product_id`
     * snapshot, scoped to the event's tenant. Returns null when the
     * snapshot isn't a UUID, or no `products` row matches in the event's
     * tenant scope (snapshot-survives-deletion semantics — sealed payload
     * remains authoritative).
     *
     * **Pass 2A.PHP.2 R2 — Codex BLOCKER-1 closure.** The lookup is
     * tenant-scoped: a `product_id` UUID that exists in a FOREIGN tenant
     * (but not in the event's own tenant) MUST return null. This
     * mirrors the Task 21 R2 Opus F3 cross-tenant FK gate for
     * `payment_method_id`. Without the tenant scope, a malicious or
     * malformed sealed payload could bind a `pos_receipt_lines.product_id`
     * row to another tenant's `products.id`, smuggling cross-tenant
     * references into reporting / stock / NF525 export joins.
     */
    private function resolveProductFk(string $tenantId, string $productSnapshot): ?string
    {
        if (! Str::isUuid($productSnapshot)) {
            return null;
        }

        try {
            $row = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('id', $productSnapshot)
                ->first('id');
        } catch (QueryException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $id = $row->id;

        return is_string($id) ? $id : (string) $id;
    }

    /**
     * Resolve the local `product_variants.id` FK for the canonical
     * `variant_id` snapshot (SaleReceiptV2 / M4). Same
     * snapshot-survives-deletion + cross-tenant-gate stance as
     * `resolveProductFk`, additionally anchored to the resolved product FK
     * so a variant of a DIFFERENT product can never bind (and the
     * `pos_receipt_lines` CHECK `variant_id IS NULL OR product_id IS NOT
     * NULL` always holds — callers only invoke this with a non-null
     * product FK).
     */
    private function resolveVariantFk(string $tenantId, string $productFk, string $variantSnapshot): ?string
    {
        if (! Str::isUuid($variantSnapshot)) {
            return null;
        }

        try {
            $row = DB::table('product_variants')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productFk)
                ->where('id', $variantSnapshot)
                ->first('id');
        } catch (QueryException) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $id = $row->id;

        return is_string($id) ? $id : (string) $id;
    }

    /**
     * Insert the `pos_receipt_vat_details` rows from the canonical view.
     *
     * The canonical `vat_breakdown[]` carries pre-computed gross_amount per
     * synthesis v5 §6.C; we mirror it straight through. `tax_category_code`
     * is canonical-only (not projected to columns).
     */
    private function writeVatBreakdown(string $receiptId, SaleReceiptCanonicalView $view): void
    {
        foreach ($view->vatBreakdown as $vat) {
            ReceiptVatDetail::query()->create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receiptId,
                'tax_rate' => $vat->rate,
                'net_amount' => $vat->netAmount,
                'vat_amount' => $vat->vatAmount,
                'gross_amount' => $vat->grossAmount,
            ]);
        }
    }

    /**
     * Insert the `pos_receipt_payments` rows from the canonical view.
     *
     * **Pass 2A.PHP.2 — `payment_method_id` resolution (synthesis v5 §8.B +
     * dispatch §0 Gap A).** The canonical payload no longer carries
     * `payment_method_id`. The projector resolves the tenant-scoped FK
     * via `PaymentMethodResolver::resolveByCode($tenantId, $methodCode)`.
     * A null return triggers fail-closed RuntimeException — the wrapping
     * `DB::transaction` rolls back atomically. This preserves the Task 21
     * R2 Opus F3 cross-tenant security stance via the Shared/Contracts
     * seam (the resolver scopes the lookup to the event's tenant; a
     * cross-tenant `method_code` collision returns null).
     *
     * Canonical-only fields NOT projected to columns:
     *   - foreign_currency_amount + foreign_currency_code (FX legs)
     */
    private function writePayments(
        string $receiptId,
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
    ): void {
        foreach ($view->payments as $payment) {
            $this->writePayment($receiptId, $event, $payment);
        }
    }

    private function writePayment(string $receiptId, FiscalEvent $event, PaymentDTO $payment): void
    {
        $methodCode = $payment->methodCode;
        $amount = $payment->amount;
        $instrumentType = $payment->instrumentType;
        $instrumentSerial = $payment->instrumentSerial;

        // Defense-in-depth B4 fence — device-side validator enforces this,
        // but a programmatic caller could bypass. Throw rolls back the
        // wrapping projection transaction.
        if (PaymentInstrumentKind::requiresInstrumentForMethodCode($methodCode)) {
            if ($instrumentType === null || $instrumentType === ''
                || $instrumentSerial === null || $instrumentSerial === '') {
                throw InstrumentRequiredException::forMethodCode($methodCode);
            }
        }

        // Resolve payment_method_id via the Shared/Contracts seam.
        $paymentMethodId = $this->paymentMethodResolver->resolveByCode(
            $event->tenant_id,
            $methodCode,
        );

        // Fail-closed when the method_code does not resolve in the tenant
        // scope. Same security stance as Task 21 R2 Opus F3 (cross-tenant
        // rejection), now expressed via the Shared/Contracts seam.
        if ($paymentMethodId === null) {
            throw new RuntimeException(sprintf(
                'PosCoreReceiptProjection: payment_method_not_found:method_code=%s:tenant_id=%s',
                $methodCode,
                $event->tenant_id,
            ));
        }

        $instrumentKind = $instrumentType !== null && $instrumentType !== ''
            ? PaymentInstrumentKind::tryFrom($instrumentType)
            : null;

        // The legacy `payment_type` snapshot column is the human-readable
        // display name of the method. Resolve once for the snapshot.
        $paymentType = $this->resolvePaymentTypeDisplayName($paymentMethodId, $methodCode);

        ReceiptPayment::query()->create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receiptId,
            'payment_method_id' => $paymentMethodId,
            'payment_type' => $paymentType,
            'payment_method_code' => $methodCode,
            'amount' => $amount,
            'card_last_four' => null,
            'transaction_reference' => null,
            'instrument_type' => $instrumentKind,
            'instrument_serial' => $instrumentSerial,
            // Treasury linkage column intentionally left NULL — the
            // Treasury bridge (Task 22) populates `treasury_payment_id`
            // when it creates the operational Payment row. POS-core
            // projection does not depend on Treasury.
            'treasury_payment_id' => null,
        ]);
    }

    /**
     * Resolve the payment-method display name for the `payment_type`
     * snapshot column. Walks the resolver again to fetch the human-readable
     * label; falls back to the method_code on resolver miss (defensive —
     * the writePayment-time resolver call already validated existence).
     *
     * The resolver returns only the UUID; for the display name we
     * intentionally use the same Shared/Contracts seam — but since the
     * interface only exposes `resolveByCode → ?string`, we fall back to
     * `method_code` as the snapshot. This is acceptable because the
     * legacy `payment_type` column is denormalized snapshot data, and the
     * canonical authoritative source is the `pos_receipt_payments.payment_method_code`
     * column we ALSO write.
     */
    private function resolvePaymentTypeDisplayName(string $paymentMethodId, string $methodCode): string
    {
        // The interface only exposes id resolution; for the legacy display
        // snapshot the method_code is the safe fallback. Pass 2B can widen
        // the seam if Treasury reporting needs the friendly name on this
        // column.
        unset($paymentMethodId);

        return $methodCode;
    }

    /**
     * Voucher redemption — iterates `payments[]` and calls
     * `VoucherRedemptionService::redeem` for every `store_voucher` instrument.
     *
     * Reads from the canonical view; the redemption call inherits the
     * wrapping DB transaction.
     */
    private function redeemVouchers(string $receiptId, FiscalEvent $event, SaleReceiptCanonicalView $view): void
    {
        $currency = $view->payload->currencyCode;

        foreach ($view->payments as $payment) {
            if ($payment->instrumentType !== PaymentInstrumentKind::StoreVoucher->value) {
                continue;
            }
            $instrumentSerial = $payment->instrumentSerial;
            if ($instrumentSerial === null || $instrumentSerial === '') {
                continue;
            }
            /** @var numeric-string $amount */
            $amount = $payment->amount;
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
     * Credit loyalty points for an earning SALE. Mirrors redeemVouchers() —
     * synchronous, try/catch, must never break the sale projection.
     * Earns only on a real SALE (not REFUND/VOID → Return, not training).
     */
    private function earnLoyaltyPoints(
        string $receiptId,
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        SaleReceiptPayload $payload,
        ReceiptType $receiptType,
        string $totalNorm,
    ): void {
        // Earn-eligibility guard (Codex BLOCKER-2): refunds/voids/training earn nothing.
        if ($receiptType !== ReceiptType::Sale || $payload->trainingFlag === true) {
            return;
        }

        try {
            $this->loyaltyEarning->earnForSale(new SaleEarnContext(
                tenantId: $event->tenant_id,
                contactId: $view->buyer?->contactId,
                partnerId: $view->buyer?->customerId,
                currency: $payload->currencyCode,
                sourceType: 'pos_receipt',
                sourceId: $receiptId,
                receiptNumber: (string) $event->sequence_number,
                postedAt: $event->event_time_device,
                earnBase: $totalNorm,
            ));
        } catch (\Throwable $e) {
            Log::error('PosCoreReceiptProjection: loyalty earn failed (sale unaffected)', [
                'fiscal_event_id' => $event->id,
                'receipt_id' => $receiptId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stock decrement for product lines — iterates the canonical view.
     *
     * **Single authoritative decrement (T2).** This projection is the SOLE
     * server-side stock decrement for a POS sale. Under device-SoT the device
     * authors the sealed `SALE_RECEIPT`; the server projects it exactly once,
     * keyed and locked on `fiscal_event_id` (fast-path `Receipt::exists()` probe
     * + the atomic `INSERT … ON CONFLICT … DO NOTHING` in
     * `insertReceiptOnConflictDoNothing`, all inside the
     * `ApplyFiscalEventProjectionJob` per-row `WithoutOverlapping` lock). The
     * legacy draft-creation path (`ReceiptCreationService::createReceipt`) that
     * historically decremented at draft time is retired — every caller is 410
     * Gone / inert (see `scripts/saleReceipt-chokepoint-manifest.json`), so the
     * pre-T2 "draft + projection" double-decrement no longer occurs.
     *
     * **Variant-aware (T2).** `LineItemDTO` carries `variant_id` since
     * SaleReceiptV2 (M4); we thread `$line->variantId` into `decrementStock()`
     * so a variant line hits the variant-scoped `stock_levels` row and writes
     * `variant_id` onto the `stock_movements` row. Non-variant lines pass null
     * and keep the product-level (`variant_id IS NULL`) behaviour.
     */
    private function decrementStockForLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        ReceiptType $receiptType,
    ): void {
        // Phase 0 fast-follow (return-disposition spec §5.1): a REFUND/VOID-via-
        // SALE_RECEIPT maps to ReceiptType::Return and must NOT decrement stock
        // (it would compound the loss). Disposition-gated restock/re-increment
        // is the Phase 3 deliverable; here we skip.
        if ($receiptType === ReceiptType::Return) {
            return;
        }

        foreach ($view->lineItems as $line) {
            $productId = $line->productId;
            // Skip stock decrement when the canonical product_id is not a
            // local FK (non-UUID snapshot, deleted product, etc.). The
            // canonical snapshot stays authoritative; stock is a downstream
            // best-effort projection.
            if ($productId === '' || ! Str::isUuid($productId)) {
                continue;
            }

            $this->decrementStock(
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                locationId: (string) $terminal->location_id,
                productId: $productId,
                quantity: $line->quantity,
                receiptId: $receiptId,
                cashierId: $event->operator_id,
                // T2 — variant-aware: a variant line scopes to the variant
                // `stock_levels` row and stamps `variant_id` on the movement;
                // a non-variant line passes null → product-level row.
                variantId: $line->variantId,
            );
        }
    }

    /**
     * Decrement stock for one product line.
     *
     * When `$variantId` is set, scopes to the variant-scoped `stock_levels`
     * row (`product_id + variant_id + location_id`). When null, scopes to the
     * product-level row (`product_id + variant_id IS NULL + location_id`).
     * `variant_id` is written onto the `stock_movements` row for downstream
     * reporting (Task 18). Column exists from Task 6.
     */
    private function decrementStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $cashierId,
        ?string $variantId = null,
    ): void {
        $stockLevelQuery = StockLevel::query()
            ->where('product_id', $productId)
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
            // A variant line with no variant-scoped stock_levels row must NOT
            // fall back to decrementing the product-level pool (the pre-T2
            // bug). Nothing is decremented; surface the absent variant grain
            // so an unseeded-variant leak is observable rather than silent.
            // Product-level lines with no row are normal (non-inventory /
            // service items) and stay silent to avoid log noise.
            if ($variantId !== null) {
                Log::warning('PosCoreReceiptProjection: variant sale found no variant-scoped stock_levels row; nothing decremented', [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'receipt_id' => $receiptId,
                ]);
            }

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
        // stock_levels.quantity and the canonical line quantity are stored at
        // scale 4 (canonical quantity storage scale). Subtract at scale 4 so
        // sub-centi quantities are not truncated to zero.
        $quantityAfter = bcsub($stockQty, $qty, 4); // 4 = canonical quantity storage scale

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        StockMovement::query()->create([
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
     * Compute the legacy `vat_breakdown_hash` value from the canonical view.
     *
     * The legacy operator command `pos:verify-chains` re-runs the hash via
     * `ReceiptHashService::hashVATBreakdown` which expects rows shaped
     * `{tax_rate, net_amount, vat_amount, gross_amount}`. Pass 2A.PHP.2
     * sources them from the canonical view's `VatBreakdownDTO[]`.
     */
    private function computeVatBreakdownHash(SaleReceiptCanonicalView $view): string
    {
        $rows = [];
        foreach ($view->vatBreakdown as $vat) {
            $rows[] = [
                'tax_rate' => $vat->rate,
                'net_amount' => $vat->netAmount,
                'vat_amount' => $vat->vatAmount,
                'gross_amount' => $vat->grossAmount,
            ];
        }

        return $this->receiptHashService->hashVATBreakdown($rows);
    }

    /**
     * Compute the legacy `payment_methods_hash` from the canonical view.
     *
     * Pass 2A.PHP.2 — the canonical payload no longer carries
     * `payment_method_id`; we resolve the display-name snapshot the SAME
     * way `writePayment()` does (via the Shared/Contracts seam) so the
     * stored hash matches what `payment_type` snapshots on each row.
     */
    private function computePaymentMethodsHash(SaleReceiptCanonicalView $view, FiscalEvent $event): string
    {
        $rows = [];
        foreach ($view->payments as $payment) {
            $paymentMethodId = $this->paymentMethodResolver->resolveByCode(
                $event->tenant_id,
                $payment->methodCode,
            );
            // Defensive fallback — `writePayment()` will throw before any
            // row lands if the resolver misses, so the projection rolls
            // back atomically and this hash is never persisted. We mirror
            // the same snapshot resolution here for determinism.
            $paymentType = $paymentMethodId !== null
                ? $this->resolvePaymentTypeDisplayName($paymentMethodId, $payment->methodCode)
                : $payment->methodCode;
            $rows[] = [
                'payment_type' => $paymentType,
                'amount' => $payment->amount,
            ];
        }

        return $this->receiptHashService->hashPaymentMethods($rows);
    }
}
