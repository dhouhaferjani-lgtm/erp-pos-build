<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Compliance\Services\AuditService;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalPayloadConstraintValidator;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalLineReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ApprovalEvidenceUnresolvedException;
use App\Modules\Fiscal\Domain\Exceptions\OriginalLineUnresolvableException;
use App\Modules\Fiscal\Domain\Exceptions\OriginalReceiptUnresolvableException;
use App\Modules\Fiscal\Domain\Exceptions\RefundQuantityExceededException;
use App\Modules\Fiscal\Domain\Exceptions\TrainingOriginalRefundRefusedException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\CountingBlockService;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\PosPaymentPolicyResolver;
use App\Modules\POS\Application\Services\ReturnScrapWriteOffService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Exceptions\InstrumentRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Application\Services\RestockPolicyResolver;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Voucher\Application\DTOs\VoucherRedemptionRequest;
use App\Modules\Voucher\Application\Services\VoucherRedemptionService;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use App\Shared\Domain\ByteaBinding;
use App\Shared\Domain\CashRoundingCutover;
use App\Shared\Domain\ConcurrencyFault;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
 * `payment_methods.id` by `(tenant_id, company_id, method_code)`. The Treasury-side
 * `Payment` row + GL is owned by `TreasuryReceiptBridge` (Task 22), which
 * runs only when the Treasury module is active.
 *
 * **Pass 2A.PHP.2 — 27-key canonical migration (synthesis v5 §8.B).**
 * Reads `fiscal_events.payload` through `CanonicalPayloadReader` for the
 * fiscal-event-backed path. The canonical SALE_RECEIPT payload no longer
 * carries `payment_method_id` per-payment — the projector resolves the
 * tenant+company-scoped FK via `PaymentMethodResolver::resolveByCode($tenantId,
 * $companyId, $payment->methodCode)`. A null return triggers fail-closed RuntimeException
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
     * `pos_receipts.cash_rounding_denomination` is decimal(15,4), matching
     * `country_payment_settings.cash_rounding_denomination`, so the policy
     * reconciliation below is a scale-4 bccomp rather than a string compare.
     */
    private const int DENOMINATION_SCALE = 4;

    /**
     * The internal at-rest cost precision of the perpetual WAC ledger — the same
     * constant `WeightedAverageCostService::COST_SCALE` and
     * `StockAdjustmentService::COST_SCALE` carry, and the scale of
     * `stock_movements.unit_cost` / `total_cost` (decimal(19,6)).
     *
     * DPA Wave 3 T5. It is a CONSTANT, never `CurrencyScaleResolver::getScale()`,
     * and that is load-bearing rather than stylistic: this projector runs in a
     * queue worker with NO `CompanyContext` (house rule 20), where the bare
     * no-arg resolver throws `UnboundCompanyContextException`
     * (`CurrencyScaleResolver.php:44-52`). The cost columns are
     * resolver-independent by construction, exactly as
     * `StockAdjustmentService::recordMovement()` already writes them — which is
     * precisely why V10 could route the fiscal projection through `issue()` and
     * V8 could not route it through `WeightedAverageCostService` (plan §0.1).
     */
    private const int COST_SCALE = 6;

    /**
     * Cash-rounding cutover version. `fiscal_events.event_version >= 3` is the
     * SINGLE discriminator gating every new write in this projection — the
     * Treasury bridge gates on the same value, so the read model and the
     * ledger can never disagree about which receipts are rounding-era. Below
     * it, this projector must stay byte-identical to its pre-rounding
     * behaviour on first apply AND on replay.
     *
     * Task 9 promoted the literal to {@see CashRoundingCutover::EVENT_VERSION}
     * so this projection and `TreasuryReceiptBridge` read the same value from
     * one place; the local alias is kept purely so the call site below still
     * reads as a POS concept.
     */
    private const int CASH_ROUNDING_EVENT_VERSION = CashRoundingCutover::EVENT_VERSION;

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
        private readonly CountingBlockService $countingBlockService,
        private readonly AuditService $auditService,
        private readonly PosPaymentPolicyResolver $posPaymentPolicyResolver,
        private readonly RestockPolicyResolver $restockPolicyResolver,
        private readonly ReturnScrapWriteOffService $returnScrapWriteOffService,
        private readonly InventoryGlPostingBuffer $glBuffer,
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

            // ---- v3-gated derivations (spec §4.5). `event_version` is the
            // ---- SINGLE cutover discriminator for both this read model and
            // ---- the Treasury bridge, so the two can never diverge. On a
            // ---- v1/v2 event every one of these stays null and the four
            // ---- columns are omitted from the INSERT entirely.
            $isV3 = $event->event_version >= self::CASH_ROUNDING_EVENT_VERSION;
            $roundingAdjustmentNorm = null;
            $roundingDenominationNorm = null;
            $changeDueNorm = null;
            $toleranceWriteoffNorm = null;

            if ($isV3) {
                $roundingAdjustmentNorm = $this->normalize($view->cashRoundingAdjustmentOrZero());
                $roundingDenominationNorm = $this->normalizeDenomination($view->cashRoundingDenominationOrZero());

                // Voucher legs ARE payment legs (`vouchers_redeemed` is derived
                // from `payments[]`), so this sum is the full tendered amount.
                $tenderedNorm = $this->normalize('0');
                foreach ($view->payments as $paymentLine) {
                    $tenderedNorm = bcadd($tenderedNorm, $this->normalize($paymentLine->amount), self::SCALE);
                }

                $overTender = bcsub($tenderedNorm, $totalNorm, self::SCALE);
                $changeDueNorm = bccomp($overTender, '0', self::SCALE) > 0
                    ? $overTender
                    : $this->normalize('0');

                $shortfall = bcsub($totalNorm, $tenderedNorm, self::SCALE);
                // Training receipts never book a write-off (they never reach GL).
                $toleranceWriteoffNorm = ($payload->trainingFlag === true)
                    ? null
                    : (bccomp($shortfall, '0', self::SCALE) > 0 ? $shortfall : $this->normalize('0'));
            }

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

            // v3-refund-chain-integration spec §3.7/§12 — v4-only server-
            // side defense, reached only when the original resolved above
            // (a v4 REFUND with no legitimate producer other than REFUND,
            // per FiscalPayloadConstraintValidator's v4 gate). Training-
            // original refusal is the device's PRIMARY gate (§3.7); this is
            // defense-in-depth for an event that somehow bypassed it.
            $refundPolicyAlerts = null;
            if ($event->event_version >= 4 && $originalReceiptId !== null) {
                $this->assertOriginalNotTraining($event, $originalReceiptId);
                $this->assertRefundQuantityWithinCap($event, $view, $originalReceiptId);
                $refundPolicyAlerts = $this->computeRefundPolicyAlerts($originalReceiptId);
                $this->assertApprovalEvidenceResolved($event, $payload);
            }

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
                // v3-refund-chain-integration spec §3.5/§3.6 — server-
                // advisory accept-and-flag column. NULL means "no alerts
                // raised" (the common case, including every non-v4-refund
                // row); never written for a receipt this projector didn't
                // just evaluate as a v4 refund.
                'refund_policy_alerts' => $refundPolicyAlerts !== null ? json_encode($refundPolicyAlerts, JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Cash-rounding columns are appended ONLY for v3+. A v1/v2 INSERT
            // therefore carries exactly the same column list it carried before
            // this feature existed, which is what makes the "v2 projects
            // byte-identically" guarantee mechanical rather than aspirational.
            //
            // The `pos_receipts_totals` CHECK is
            // `total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)`,
            // so a v3 row that omitted `cash_rounding_adjustment` would be
            // rejected outright by PostgreSQL — the constraint is the backstop
            // for this branch, not a separate concern.
            if ($isV3) {
                $row['cash_rounding_adjustment'] = $roundingAdjustmentNorm;
                $row['cash_rounding_denomination'] = $roundingDenominationNorm;
                $row['change_due'] = $changeDueNorm;
                $row['tolerance_writeoff'] = $toleranceWriteoffNorm;
            }

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

            $lineProjection = $this->writeLines(
                $receiptId,
                $event,
                $terminal,
                $view,
                $receiptTypeEnum,
                $originalReceiptId,
            );
            $this->writeVatBreakdown($receiptId, $view);
            $this->writePayments($receiptId, $event, $view);
            // Spec §4.5 consumer matrix: loyalty earns on the SALE VALUE
            // (total − adj), never on the rounded amount collected. On v1/v2
            // there is no adjustment, so the base stays the projected total.
            $earnBase = $roundingAdjustmentNorm !== null
                ? bcsub($totalNorm, $roundingAdjustmentNorm, self::SCALE)
                : $totalNorm;

            $this->earnLoyaltyPoints($receiptId, $event, $view, $payload, $receiptTypeEnum, $earnBase);
            $this->applyStockMovementForLines(
                $receiptId,
                $event,
                $terminal,
                $view,
                $receiptTypeEnum,
                $payload->currencyCode,
                $postedAt,
                $originalReceiptId,
                $lineProjection['unit_costs'],
                $lineProjection['stock_movement_expected'],
            );

            // Voucher redemption reaches the company GL advisory. It is
            // independent of stock projection and therefore belongs after the
            // final inventory lock, with its entry bytes and date unchanged.
            $this->redeemVouchers($receiptId, $event, $view, $receiptTypeEnum);

            // Drift telemetry only — and only for a receipt that actually
            // rounded. A zero adjustment signed no denomination, so there is
            // nothing to reconcile against policy. A non-null adjustment
            // implies the v3 branch above ran, hence a non-null denomination.
            if ($roundingAdjustmentNorm !== null
                && bccomp($roundingAdjustmentNorm, '0', self::SCALE) !== 0) {
                $this->reconcileRoundingPolicySafely($event, $roundingDenominationNorm);
            }

            try {
                $this->glBuffer->flushIfOutermost(contained: true);
            } catch (\Throwable $e) {
                if (ConcurrencyFault::isRetryable($e)) {
                    throw $e;
                }

                Log::error('PosCoreReceiptProjection: inventory GL batch failed; every inventory entry for the receipt was discarded', [
                    'fiscal_event_id' => $event->id,
                    'receipt_id' => $receiptId,
                    'error' => $e->getMessage(),
                ]);
            }
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

        // `pos_receipts.canonical_bytes` is BINARY — bind it as PDO::PARAM_LOB
        // or PostgreSQL parses it with its bytea *escape* input rules: `\"`
        // raises SQLSTATE 22P02 and `\\` is silently collapsed to one byte.
        // Done BEFORE the column list is derived so the two can never disagree.
        [$row, $streams] = ByteaBinding::prepareRow($row, ['canonical_bytes']);

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

        try {
            $rows = DB::select($sql, $bindings);
        } finally {
            ByteaBinding::closeAll($streams);
        }

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
     * v3-refund-chain-integration spec §3.7 (T4) — server-side defense-in-
     * depth mirroring the device's primary training-original refusal.
     * Reads `training_flag` from the RESOLVED ORIGINAL's own signed
     * fiscal-event payload — never from the CURRENT event's
     * `payload->trainingFlag` (an unrelated concept: whether the refund
     * itself is training, not whether the original sale being refunded
     * was). A training original refunded outside training mode (or a
     * non-training original refunded from a training session) is a
     * separate, orthogonal question this check does not answer — see
     * §10's `redeemVouchers`/`earnLoyaltyPoints` gates for the CURRENT
     * event's own training exclusion.
     */
    private function assertOriginalNotTraining(FiscalEvent $event, string $originalReceiptId): void
    {
        $originalEvent = $this->resolveOriginalFiscalEvent($originalReceiptId);
        if ($originalEvent === null || ! is_array($originalEvent->payload)) {
            return;
        }

        if (($originalEvent->payload['training_flag'] ?? null) === true) {
            throw new TrainingOriginalRefundRefusedException(
                fiscalEventId: $event->id,
                originalFiscalEventId: $originalEvent->id,
            );
        }
    }

    /**
     * v3-refund-chain-integration spec §3.5/§3.6 — server-advisory accept-
     * and-flag alerts, computed purely from data already available AFTER
     * the refund event is signed (Model 1, §4.1: an already-signed event
     * is never silently dropped on account of a policy flag).
     *
     * **Errata F1 — timestamp-only, not permission-based.** The projector
     * runs on a Horizon worker with no bound Spatie permissions team (rule
     * 20); a permission-set check here would silently evaluate against an
     * unbound/wrong team and produce a meaningless result, not a real
     * policy signal. Any manager-threshold/daily-cap advisory that would
     * require a permission check is out of `refund_policy_alerts`'
     * launch scope entirely (deferred to §16.5's signed-snapshot
     * mechanism) — not silently half-implemented here.
     *
     * **Return-window advisory — N/A for launch, stated explicitly, not
     * silently omitted.** §3.6 authorizes "whatever policy questions [the
     * projector] can compute purely from the original receipt's own
     * timestamp" — but no return-window DURATION exists anywhere as a
     * source of truth (no company/tenant policy field, no config
     * constant; `RefundWindowClosedException` is a reserved-but-never-
     * thrown type with no caller supplying `expiryDays` anywhere in this
     * codebase). Computing a window advisory would require inventing an
     * un-authorized business rule, which this launch does not do. Only
     * the §3.5 alert below — fully specified, no missing input — is
     * implemented.
     *
     * **§3.5 alert — non-zero original transaction_discount_amount.** A
     * structural impossibility for a device-authored v4 refund (§3.5's
     * refusal makes this unreachable from a correctly-behaving device),
     * so its presence here signals a bypassed or compromised client.
     *
     * @return list<array<string, mixed>>|null null when no alert fired
     */
    private function computeRefundPolicyAlerts(string $originalReceiptId): ?array
    {
        $originalEvent = $this->resolveOriginalFiscalEvent($originalReceiptId);
        if ($originalEvent === null || ! is_array($originalEvent->payload)) {
            return null;
        }

        $alerts = [];

        $originalDiscount = $originalEvent->payload['transaction_discount_amount'] ?? null;
        if (is_string($originalDiscount) && is_numeric($originalDiscount)) {
            $scale = (int) ($originalEvent->payload['currency_scale'] ?? 2);
            if (bccomp($originalDiscount, '0', $scale) !== 0) { // precision-ok: scale read from the original's own signed payload
                $alerts[] = [
                    'type' => 'non_zero_original_transaction_discount',
                    'detected_at' => now('UTC')->toIso8601String(),
                    'original_fiscal_event_id' => $originalEvent->id,
                    'original_transaction_discount_amount' => $originalDiscount,
                ];
            }
        }

        return $alerts === [] ? null : $alerts;
    }

    /**
     * Resolve the RESOLVED ORIGINAL's own signed `fiscal_events` row for a
     * refund, via the local `pos_receipts.fiscal_event_id` link. Shared by
     * {@see assertOriginalNotTraining()} and
     * {@see computeRefundPolicyAlerts()} — both read exclusively from the
     * original's OWN payload, never the current refund event's.
     */
    private function resolveOriginalFiscalEvent(string $originalReceiptId): ?FiscalEvent
    {
        /** @var Receipt|null $original */
        $original = Receipt::query()->find($originalReceiptId);
        if ($original === null || $original->fiscal_event_id === null) {
            // Legacy-sealed (pre-fiscal-events) or otherwise unresolvable
            // original — no signed payload to read.
            return null;
        }

        return FiscalEvent::query()->find($original->fiscal_event_id);
    }

    /**
     * v3-refund-chain-integration spec §12 — per-original quantity cap.
     *
     * Locks the ORIGINAL receipt's `pos_receipt_lines` rows
     * (`FOR UPDATE`) so two concurrent refund projections against the SAME
     * original serialize rather than both reading a stale
     * already-refunded sum. For each `original_line_references[]` entry,
     * sums `ABS(quantity)` across every EXISTING `pos_receipt_lines` row
     * referencing that original line (`original_line_id`) — the `ABS()`
     * normalizes both the legacy negative convention
     * (`ReceiptReturnService.php:955-975`) and the v4 positive-magnitude
     * convention (§3.1) to their true magnitude, so a mixed-legacy
     * population (some prior refunds via the legacy path, some via v4)
     * is counted correctly. Throws
     * {@see RefundQuantityExceededException} — a
     * `NonRetryableProjectionException` (§4.2) — when the cumulative
     * total, INCLUDING the quantity this event is requesting, would
     * exceed the original line's own quantity.
     */
    private function assertRefundQuantityWithinCap(FiscalEvent $event, SaleReceiptCanonicalView $view, string $originalReceiptId): void
    {
        $originalLineReferences = $view->originalLineReferences();
        if ($originalLineReferences === null || $originalLineReferences === []) {
            return;
        }

        $originalLines = DB::table('pos_receipt_lines')
            ->where('receipt_id', $originalReceiptId)
            ->orderBy('line_number')
            ->lockForUpdate()
            ->get(['id', 'line_number', 'quantity', 'product_id']);

        foreach ($originalLineReferences as $ref) {
            $originalLine = $this->resolveOriginalLineForReference($event, $originalReceiptId, $ref, $originalLines);

            $originalLineId = (string) $originalLine->id;
            /** @var numeric-string $originalQuantity */
            $originalQuantity = (string) $originalLine->quantity;

            // review round-2 IMPORTANT 12 — concurrent-redelivery exclusion.
            // Two racing projector dispatches for the SAME fiscal_event_id
            // can both pass the top-of-apply() idempotency fast-path before
            // either commits; the loser then blocks on this method's
            // `lockForUpdate()` until the winner commits, unblocks, and
            // re-sums — at which point the SUM would otherwise include the
            // WINNER'S own just-committed lines for this SAME event,
            // double-counting them against the loser's identical request
            // and throwing a false RefundQuantityExceededException. Exclude
            // rows whose `pos_receipts.fiscal_event_id` equals the CURRENT
            // event's own id (a prior successful application of THIS event,
            // not a genuinely separate refund) from the "already refunded"
            // sum. `whereNull` keeps legacy-authored return lines
            // (`fiscal_event_id IS NULL`) counted — `<>` alone would drop
            // them (SQL NULL comparison), so both branches are required.
            /** @var numeric-string $alreadyRefunded */
            $alreadyRefunded = (string) (DB::table('pos_receipt_lines')
                ->join('pos_receipts', 'pos_receipts.id', '=', 'pos_receipt_lines.receipt_id')
                ->where('pos_receipt_lines.original_line_id', $originalLineId)
                ->where(function ($query) use ($event): void {
                    $query->whereNull('pos_receipts.fiscal_event_id')
                        ->orWhere('pos_receipts.fiscal_event_id', '<>', $event->id);
                })
                ->selectRaw('COALESCE(SUM(ABS(pos_receipt_lines.quantity)), 0) as total')
                ->value('total') ?? '0');

            /** @var numeric-string $requested */
            $requested = $ref->quantity;

            $projected = bcadd($alreadyRefunded, $requested, 4); // precision-ok: 4 = canonical quantity storage scale

            if (bccomp($projected, $originalQuantity, 4) > 0) { // precision-ok: 4 = canonical quantity storage scale
                throw new RefundQuantityExceededException(
                    fiscalEventId: $event->id,
                    originalLineId: $originalLineId,
                    originalQuantity: $originalQuantity,
                    alreadyRefundedQuantity: $alreadyRefunded,
                    requestedQuantity: $requested,
                );
            }
        }
    }

    /**
     * v3-refund-chain-integration spec §3.3/§12 review round-2 CRITICAL 1
     * — the SHARED resolution both {@see assertRefundQuantityWithinCap()}
     * and {@see writeLines()} use for every `original_line_references[]`
     * row, so the two can never drift into disagreeing about which
     * original line a reference resolves to.
     *
     * `FiscalPayloadConstraintValidator::validateOriginalLineReferences()`
     * only checks the refund's OWN payload for internal consistency
     * (`original_line_references[i]` matches `line_items[i]` at signing
     * time) — it has no access to the ORIGINAL receipt's actual projected
     * `pos_receipt_lines` rows, so it cannot catch a reference whose
     * `original_line_index` doesn't exist on the original. That is
     * detected here against the real `pos_receipt_lines` rows (by
     * `line_number`); `product_id` is instead cross-checked against the
     * ORIGINAL's own SIGNED PAYLOAD snapshot
     * (`fiscal_events.payload.line_items[i].product_id`), never the local
     * `pos_receipt_lines.product_id` FK column — `writeLines()`
     * deliberately NULLs that FK for deleted/ad-hoc/cross-tenant products,
     * so the lossy local column would falsely dead-letter a genuinely
     * correct reference.
     *
     * @param  Collection<int, \stdClass>  $originalLines  each row carrying at least `id`, `line_number` (raw `DB::table('pos_receipt_lines')->get()` rows; used ONLY to resolve `line_number` → the local line's `id`/`quantity` for the §12 cap — never for the `product_id` check, see below)
     */
    private function resolveOriginalLineForReference(
        FiscalEvent $event,
        string $originalReceiptId,
        OriginalLineReferenceDTO $ref,
        Collection $originalLines,
    ): \stdClass {
        $originalLine = $originalLines->first(
            fn (\stdClass $row): bool => (int) $row->line_number === $ref->originalLineIndex + 1
        );
        if ($originalLine === null) {
            throw new OriginalLineUnresolvableException(
                fiscalEventId: $event->id,
                originalReceiptId: $originalReceiptId,
                originalLineIndex: $ref->originalLineIndex,
                reason: 'no pos_receipt_lines row exists at that line_number on the resolved original receipt',
            );
        }

        // fiscal re-verification IMPORTANT — compare against the
        // ORIGINAL'S OWN SIGNED PAYLOAD snapshot
        // (fiscal_events.payload.line_items[i].product_id), NEVER the
        // lossy LOCAL `pos_receipt_lines.product_id` FK column.
        // `writeLines()` deliberately NULLs that FK for deleted/ad-hoc/
        // cross-tenant products (see its own comment) — comparing against
        // it here means a refund of such an original dead-letters
        // PERMANENTLY on the primary path even though the reference is
        // genuinely correct. The signed snapshot survives deletion and FK
        // suppression by construction (it is chain-immutable and was
        // already validated at ingest time), matching the pattern already
        // used by {@see assertOriginalNotTraining()} and
        // {@see computeRefundPolicyAlerts()}.
        $originalEvent = $this->resolveOriginalFiscalEvent($originalReceiptId);
        $lineItems = ($originalEvent !== null && is_array($originalEvent->payload))
            ? ($originalEvent->payload['line_items'] ?? null)
            : null;

        if (
            ! is_array($lineItems)
            || ! isset($lineItems[$ref->originalLineIndex])
            || ! is_array($lineItems[$ref->originalLineIndex])
        ) {
            throw new OriginalLineUnresolvableException(
                fiscalEventId: $event->id,
                originalReceiptId: $originalReceiptId,
                originalLineIndex: $ref->originalLineIndex,
                reason: 'the resolved original fiscal event has no signed line_items entry at that index',
            );
        }

        $snapshotProductIdRaw = $lineItems[$ref->originalLineIndex]['product_id'] ?? null;
        $snapshotProductId = $snapshotProductIdRaw === null ? null : (string) $snapshotProductIdRaw;

        if ($snapshotProductId !== $ref->productId) {
            throw new OriginalLineUnresolvableException(
                fiscalEventId: $event->id,
                originalReceiptId: $originalReceiptId,
                originalLineIndex: $ref->originalLineIndex,
                reason: sprintf(
                    'product_id mismatch: reference claims %s, original signed line_items[%d].product_id resolved to %s',
                    $ref->productId,
                    $ref->originalLineIndex,
                    $snapshotProductId ?? 'NULL',
                ),
            );
        }

        return $originalLine;
    }

    /**
     * v3-refund-chain-integration spec §4.2 — projector-side seven-field
     * approval-evidence verification. Design "unchanged from Revision 3"
     * (Codex r3 confirmed it closes the round-2 recovery-key concern); this
     * is its first actual implementation — Revision 3 declared it in prose
     * only, and {@see ApprovalEvidenceUnresolvedException} existed as an
     * unused shell until now.
     *
     * `SALE_RECEIPT.approval_references[]` is already structurally
     * validated at ingestion by
     * {@see FiscalPayloadConstraintValidator::validateSaleReceiptApprovalReference()}
     * — every row is guaranteed to carry exactly the seven keys
     * `approval_event_id`, `approval_id`, `approval_scope`,
     * `override_event_id`, `policy_version`, `supervisor_user_id`,
     * `target_reference_id`. What that validator does NOT do — because it
     * only sees the current event's own payload — is confirm those seven
     * values actually correspond to two REAL, signed fiscal events. This
     * method does: for each reference row it resolves the cited
     * `OPERATOR_APPROVAL_GRANTED` event (by `approval_event_id`) and the
     * cited `OVERRIDE_*` event (by `override_event_id`), both tenant- AND
     * company-scoped to `$event` (same cross-tenant-smuggling stance as
     * {@see resolveProductFk()} — a forged/foreign UUID must never resolve),
     * then cross-checks `approval_id` / `approval_scope` / `policy_version`
     * / `supervisor_user_id` against BOTH resolved events' own payloads, the
     * override event's OWN `approval_event_id` against the reference row's,
     * and `target_reference_id` against the override event's
     * `override_context.target_reference_id`. Any absence or mismatch
     * throws {@see ApprovalEvidenceUnresolvedException} — a
     * `NonRetryableProjectionException` (§4.2): evidence that does not
     * exist or does not match can never resolve itself on a later Horizon
     * attempt.
     *
     * Scoped to v4 REFUND only (this method's sole call site), matching the
     * manifest's placement of this bullet among the v4-only projector
     * additions — `approval_references[]` can in principle appear on any
     * SALE_RECEIPT version, but re-verifying it for every historical v1-v3
     * sale receipt already accepted into the fiscal chain is out of this
     * feature's scope and would be a new, unrequested regression surface.
     */
    private function assertApprovalEvidenceResolved(FiscalEvent $event, SaleReceiptPayload $payload): void
    {
        foreach ($payload->approvalReferences as $index => $reference) {
            $this->assertApprovalReferenceRowResolved($event, $reference, $index);
        }
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function assertApprovalReferenceRowResolved(FiscalEvent $event, array $reference, int $index): void
    {
        $approvalEventId = (string) ($reference['approval_event_id'] ?? '');
        $overrideEventId = (string) ($reference['override_event_id'] ?? '');

        $approvalEvent = FiscalEvent::query()
            ->where('id', $approvalEventId)
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('event_type', FiscalEventType::OPERATOR_APPROVAL_GRANTED)
            ->first();

        if ($approvalEvent === null || ! is_array($approvalEvent->payload)) {
            throw new ApprovalEvidenceUnresolvedException(
                fiscalEventId: $event->id,
                approvalEventId: $approvalEventId,
                reason: sprintf('approval_references[%d]: approval_event_id does not resolve to a signed OPERATOR_APPROVAL_GRANTED event for this tenant/company', $index),
            );
        }

        $overrideEvent = FiscalEvent::query()
            ->where('id', $overrideEventId)
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->whereIn('event_type', [
                FiscalEventType::OVERRIDE_CREDIT_LIMIT,
                FiscalEventType::OVERRIDE_ACCOUNT_STATUS,
                FiscalEventType::OVERRIDE_DISCOUNT_LIMIT,
                FiscalEventType::OVERRIDE_TENDER_TOLERANCE,
                FiscalEventType::OVERRIDE_VOID_OR_RETURN,
            ])
            ->first();

        if ($overrideEvent === null || ! is_array($overrideEvent->payload)) {
            throw new ApprovalEvidenceUnresolvedException(
                fiscalEventId: $event->id,
                approvalEventId: $approvalEventId,
                reason: sprintf('approval_references[%d]: override_event_id=%s does not resolve to a signed OVERRIDE_* event for this tenant/company', $index, $overrideEventId),
            );
        }

        $approvalPayload = $approvalEvent->payload;
        $overridePayload = $overrideEvent->payload;

        foreach (['approval_id', 'approval_scope', 'policy_version', 'supervisor_user_id'] as $field) {
            $referenceValue = $reference[$field] ?? null;
            if ($referenceValue !== ($approvalPayload[$field] ?? null) || $referenceValue !== ($overridePayload[$field] ?? null)) {
                throw new ApprovalEvidenceUnresolvedException(
                    fiscalEventId: $event->id,
                    approvalEventId: $approvalEventId,
                    reason: sprintf('approval_references[%d]: %s mismatch across reference/approval/override', $index, $field),
                );
            }
        }

        if (($overridePayload['approval_event_id'] ?? null) !== $approvalEventId) {
            throw new ApprovalEvidenceUnresolvedException(
                fiscalEventId: $event->id,
                approvalEventId: $approvalEventId,
                reason: sprintf('approval_references[%d]: override event\'s own approval_event_id does not match the reference row', $index),
            );
        }

        $overrideContext = $overridePayload['override_context'] ?? null;
        $targetReferenceId = is_array($overrideContext) ? ($overrideContext['target_reference_id'] ?? null) : null;
        if ($targetReferenceId !== ($reference['target_reference_id'] ?? null)) {
            throw new ApprovalEvidenceUnresolvedException(
                fiscalEventId: $event->id,
                approvalEventId: $approvalEventId,
                reason: sprintf('approval_references[%d]: target_reference_id mismatch against override_context', $index),
            );
        }
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
     *
     * **v4 REFUND `original_line_id` write (spec §3.3/§17).** When the
     * canonical view carries `original_line_references[]` (v4 REFUND
     * only), each written line's `original_line_id` is resolved from the
     * ORIGINAL receipt's own `pos_receipt_lines` via the SAME
     * `original_line_index + 1 == line_number` mapping
     * {@see assertRefundQuantityWithinCap()} uses — this is what makes a
     * v4-authored refund line participate in §12's `SUM(ABS(quantity))`
     * cap query and in the legacy `ReceiptReturnService::
     * calculateAlreadyReturnedQuantities()` `original_line_id`-keyed
     * lookup, exactly like a legacy-authored return line already does.
     */
    /**
     * @return array{
     *     unit_costs: array<int, numeric-string|null>,
     *     stock_movement_expected: array<int, bool>
     * }
     */
    private function writeLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        ReceiptType $receiptType,
        ?string $originalReceiptId = null,
    ): array {
        // review round-2 CRITICAL 1(c) — SHARED resolution with
        // assertRefundQuantityWithinCap() via resolveOriginalLineForReference()
        // so original_line_id is NEVER silently null on a v4 refund: every
        // reference either resolves to a real, product_id-matching original
        // line or throws OriginalLineUnresolvableException (both this
        // method and the cap check throw the SAME way, so a refund whose
        // cap check already passed can never subsequently write a null
        // original_line_id here).
        $originalLineReferences = $view->originalLineReferences();
        $originalLineIdByIndex = [];
        if ($originalLineReferences !== null && $originalReceiptId !== null) {
            $originalLines = DB::table('pos_receipt_lines')
                ->where('receipt_id', $originalReceiptId)
                ->get(['id', 'line_number', 'quantity', 'product_id']);
            foreach ($originalLineReferences as $index => $ref) {
                $matchingLine = $this->resolveOriginalLineForReference($event, $originalReceiptId, $ref, $originalLines);
                $originalLineIdByIndex[$index] = (string) $matchingLine->id;
            }
        }

        $lineNumber = 1;
        $lineUnitCosts = [];
        $lineStockMovementExpected = [];
        foreach ($view->lineItems as $index => $line) {
            $originalLineReference = $originalLineReferences[$index] ?? null;
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
            $lineUnitCost = null;
            if ($receiptType === ReceiptType::Sale && $productFk !== null) {
                if (! is_numeric($line->quantity)) {
                    throw new \LogicException('Canonical POS line quantity must be numeric.');
                }
                $lineUnitCost = $this->movementCostSnapshot(
                    $event->tenant_id,
                    $event->company_id,
                    $line->productId,
                    $line->quantity,
                )['unit_cost'];
            }
            $lineUnitCosts[$index] = $lineUnitCost;
            $stockGrainExists = $productFk !== null && $this->stockGrainExists(
                $event->company_id,
                (string) $terminal->location_id,
                $productFk,
                $line->variantId,
            );
            // A missing product-level grain is the writers' established
            // non-stock-tracked outcome. A variant line is different: its
            // missing exact grain is an actionable seeding leak, so retain
            // the warning and D-f signal rather than classifying it away.
            $stockTrackingExpected = $productFk !== null
                && ($line->variantId !== null || $stockGrainExists);
            $stockMovementExpected = $stockTrackingExpected;
            if ($receiptType === ReceiptType::Return && $originalLineReference !== null) {
                $disposition = ReturnLineDisposition::tryFrom($originalLineReference->disposition);
                $stockMovementExpected = match ($disposition) {
                    ReturnLineDisposition::NotReceived => false,
                    ReturnLineDisposition::Restock => $stockTrackingExpected
                        && $this->restockPolicyResolver->resolve($productFk)->policy !== RestockPolicy::Never,
                    ReturnLineDisposition::Scrap => $stockTrackingExpected
                        && Product::query()
                            ->where('tenant_id', $event->tenant_id)
                            ->where('company_id', $event->company_id)
                            ->whereKey($productFk)
                            ->exists(),
                    default => $stockTrackingExpected,
                };
            }
            $lineStockMovementExpected[$index] = $stockMovementExpected;

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
                'unit_cost' => $lineUnitCost,
                'line_total' => $line->lineSubtotal,
                'tax_rate' => $line->vatRate,
                'tax_amount' => $line->lineVat,
                'discount_amount' => $line->lineDiscountAmount,
                'discount_reason' => $line->lineDiscountReason,
                'original_line_id' => $originalLineIdByIndex[$index] ?? null,
                // Detector D-f must distinguish an intentional no-movement
                // refund from a projection hole without re-parsing the sealed
                // event. This is a projection column; canonical bytes remain
                // authoritative and unchanged.
                'disposition' => $originalLineReference === null
                    ? null
                    : ReturnLineDisposition::tryFrom($originalLineReference->disposition)?->value,
                'stock_movement_expected' => $stockMovementExpected,
            ]);
        }

        return [
            'unit_costs' => $lineUnitCosts,
            'stock_movement_expected' => $lineStockMovementExpected,
        ];
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
     * `payment_method_id`. The projector resolves the tenant+company-scoped FK
     * via `PaymentMethodResolver::resolveByCode($tenantId, $companyId, $methodCode)`.
     * A null return triggers fail-closed RuntimeException — the wrapping
     * `DB::transaction` rolls back atomically. This preserves the Task 21
     * R2 Opus F3 cross-tenant security stance via the Shared/Contracts
     * seam (the resolver scopes the lookup to the event's tenant and company;
     * a cross-tenant or cross-company `method_code` collision returns null).
     *
     * Canonical-only fields NOT projected to columns:
     *   - foreign_currency_amount + foreign_currency_code (FX legs)
     *
     * **Two-semantics rule (spec §4.6).** `pos_receipt_payments.amount` is the
     * TENDERED amount — exactly what the canonical payload carries and what
     * the customer handed over. Treasury's `payments.amount` is the RETAINED
     * amount (tendered minus change), computed by
     * `TreasuryReceiptBridge::computeNettedAmounts()`. The two columns are
     * DELIBERATELY different numbers; never reconcile them directly. The
     * over-tender that separates them is materialized once, here, as
     * `pos_receipts.change_due`.
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
            $event->company_id,
            $methodCode,
        );

        // Fail-closed when the method_code does not resolve in the tenant
        // and company scope. Same security stance as Task 21 R2 Opus F3,
        // now extended to repeated codes across companies via the seam.
        if ($paymentMethodId === null) {
            throw new RuntimeException(sprintf(
                'PosCoreReceiptProjection: payment_method_not_found:method_code=%s:tenant_id=%s:company_id=%s',
                $methodCode,
                $event->tenant_id,
                $event->company_id,
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
     *
     * **§10 defense-in-depth gate (v3-refund-chain-integration spec, fold
     * item 10).** `store_voucher` is excluded from the v4 launch enum
     * (§3.4's single `refund_destination: 'cash'` literal) and a v4
     * REFUND's `payments[]` is a single CASH leg by construction (§3.5) —
     * so this branch is structurally unreachable for a well-formed v4
     * event. Gated on `ReceiptType::Sale`, matching the EXACT conditional
     * shape of the sibling `earnLoyaltyPoints()` gate
     * (`$receiptType !== ReceiptType::Sale`, mirrored per §10's wording
     * fix) rather than a differently-shaped `invoice_type_code !==
     * 'REFUND'` check — both are logically equivalent for this launch (a
     * REFUND event always resolves to `ReceiptType::Return`, never
     * `Sale`), but keying on the same enum + comparison form as the
     * sibling gate keeps the two defense-in-depth checks structurally
     * consistent.
     */
    private function redeemVouchers(string $receiptId, FiscalEvent $event, SaleReceiptCanonicalView $view, ReceiptType $receiptType): void
    {
        if ($receiptType !== ReceiptType::Sale) {
            return;
        }

        // LEDGER gate G-3 — a TRAINING receipt must not burn a REAL voucher.
        // `resolveReceiptType()` maps TRAINING to `ReceiptType::Sale`, so the
        // gate above passes and this method is genuinely reached for a
        // training receipt. Redemption is not a read-model write: it
        // extinguishes the customer's outstanding voucher liability AND posts
        // Dr VoucherLiability / Cr PosTenderClearing. TreasuryReceiptBridge
        // now returns early for training (same gate, same flag), so without
        // this guard the clearing credit would never get its offsetting debit
        // — a permanently unmatched PosTenderClearing balance on top of a real
        // voucher the customer can no longer spend.
        //
        // Keyed on the SEALED payload flag, matching the bridge and
        // `earnLoyaltyPoints()` below, so the three training gates cannot
        // drift apart.
        if ($view->payload->trainingFlag === true) {
            return;
        }

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
     *
     * `$earnBase` is the SALE VALUE, not the projected total: from v3 onward
     * the caller passes `total − cash_rounding_adjustment` so the customer
     * neither gains nor loses points because the drawer rounded (spec §4.5).
     * On v1/v2 there is no adjustment and the two are the same number.
     */
    private function earnLoyaltyPoints(
        string $receiptId,
        FiscalEvent $event,
        SaleReceiptCanonicalView $view,
        SaleReceiptPayload $payload,
        ReceiptType $receiptType,
        string $earnBase,
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
                earnBase: $earnBase,
                items: array_map(static fn (LineItemDTO $li): array => [
                    'product_id' => $li->productId,
                    'quantity' => $li->quantity,
                ], $view->lineItems()),
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
     * Apply the line-level stock effect for the projected receipt, branching on
     * the resolved receipt type:
     *   - `ReceiptType::Sale`   → decrement (goods leave the shelf).
     *   - `ReceiptType::Return` → restock (a REFUND/VOID returns goods to the
     *     shelf — see `docs/handoff/HANDOVER-refund-void-stock-decrement.md`).
     *
     * **Direction lives in `invoice_type_code`, never in the quantity sign.**
     * The canonical `line_items[].quantity` is a POSITIVE MAGNITUDE for every
     * invoice type — the fiscal payload validator's quantity regex
     * (`moneyRegex(QUANTITY_SCALE=3)`) forbids a leading `-`. So a Return adds
     * back the same magnitude the original sale subtracted, netting the two
     * movements to zero. Pre-fix this method unconditionally decremented, so a
     * refund double-removed stock (original sale −q, refund −q again).
     *
     * Both branches keep the same grain discipline (variant-scoped vs
     * product-level), idempotency (the whole `apply()` is guarded by the
     * `fiscal_event_id` probe + `INSERT … ON CONFLICT DO NOTHING`), and scale-4
     * bcmath precision; neither touches `CompanyContext` (rule 20).
     *
     * @param  array<int, numeric-string|null>  $lineUnitCosts
     * @param  array<int, bool>  $lineStockMovementExpected
     */
    private function applyStockMovementForLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        ReceiptType $receiptType,
        string $currencyCode,
        CarbonInterface $entryDate,
        ?string $originalReceiptId,
        array $lineUnitCosts,
        array $lineStockMovementExpected,
    ): void {
        if ($receiptType === ReceiptType::Return) {
            $this->restockForLines(
                $receiptId,
                $event,
                $terminal,
                $view,
                $currencyCode,
                $entryDate,
                $originalReceiptId,
                $lineStockMovementExpected,
            );

            return;
        }

        $this->decrementStockForLines(
            $receiptId,
            $event,
            $terminal,
            $view,
            $receiptType,
            $currencyCode,
            $entryDate,
            $lineUnitCosts,
        );

        // Live inventory counting task C2: a signed sale can never be rejected
        // server-side (device-SoT). If it arrives against an ACTIVE
        // sales-blocking count at this location and its DEVICE event time falls
        // inside the block window, the sale is ACCEPTED (stock already moved
        // above) and we append {receipt_id, occurred_at} to the counting's
        // `late_sales_flags` so review can reconcile the leak.
        //
        // The flag-append is ADVISORY ONLY — replay reconciles via occurred_at
        // regardless. A counting-subsystem exception (service lookup, lock,
        // JSON append, save) must NEVER fail/retry the fiscal projector, so we
        // wrap in try/catch and swallow.
        try {
            $this->flagLateSaleForActiveBlock($receiptId, $event, $terminal);
        } catch (\Throwable $e) {
            Log::warning('PosCoreReceiptProjection: late-sale flag-append failed (advisory, sale unaffected)', [
                'fiscal_event_id' => $event->id,
                'receipt_id' => $receiptId,
                'location_id' => $terminal->location_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Append a late-sale flag when this sale landed inside an active
     * sales-blocking count window for the terminal's location.
     *
     * Runs inside `apply()`'s `DB::transaction`, AFTER the stock decrement, so
     * the flag and the movement commit atomically. Idempotent: the outer
     * fiscal_event_id guard prevents replay, and we additionally dedupe by
     * `receipt_id`. A `lockForUpdate` on the counting row serializes concurrent
     * appends from different receipts against the same jsonb array.
     *
     * Rule 20 — this runs in the queued projector with NO CompanyContext;
     * `CountingBlockService` resolves company from the location id itself.
     */
    private function flagLateSaleForActiveBlock(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
    ): void {
        $block = $this->countingBlockService->activeBlockFor((string) $terminal->location_id);
        if ($block === null) {
            return;
        }

        $activatedAt = $block->activated_at;
        $occurredAt = $event->event_time_device;

        // A sale authored BEFORE the block opened (e.g. an offline receipt
        // syncing late) is not a sale "during the count" — never flag it.
        if ($activatedAt === null || $occurredAt->lessThan($activatedAt)) {
            return;
        }

        /** @var InventoryCounting|null $locked */
        $locked = InventoryCounting::query()
            ->whereKey($block->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            return;
        }

        $flags = $locked->late_sales_flags ?? [];
        foreach ($flags as $flag) {
            // Defensive: `late_sales_flags` is a raw JSON column — the model's
            // docblock shape is aspirational, not enforced at write time, so a
            // legacy/malformed row must not fatal the queued worker.
            /** @phpstan-ignore function.alreadyNarrowedType, nullCoalesce.offset */
            if (is_array($flag) && ($flag['receipt_id'] ?? null) === $receiptId) {
                return;
            }
        }

        $flags[] = [
            'receipt_id' => $receiptId,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];
        $locked->late_sales_flags = $flags;
        $locked->save();
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
     *
     * @param  array<int, numeric-string|null>  $lineUnitCosts
     */
    private function decrementStockForLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        ReceiptType $receiptType,
        string $currencyCode,
        CarbonInterface $entryDate,
        array $lineUnitCosts,
    ): void {
        // Phase 0 fast-follow (return-disposition spec §5.1): a REFUND/VOID-via-
        // SALE_RECEIPT maps to ReceiptType::Return and must NOT decrement stock
        // (it would compound the loss). Disposition-gated restock/re-increment
        // is the Phase 3 deliverable; here we skip.
        if ($receiptType === ReceiptType::Return) {
            return;
        }

        foreach ($view->lineItems as $index => $line) {
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
                // occurred_at = DEVICE event time (offline-authored sale projected
                // later); the replay must order by when the sale happened, not
                // when the server inserted the row.
                occurredAt: $event->event_time_device,
                currencyCode: $currencyCode,
                entryDate: $entryDate,
                unitCost: $lineUnitCosts[$index] ?? null,
            );
        }
    }

    /**
     * The cost snapshot for a POS stock movement (DPA Wave 3 T5).
     *
     * Reads `products.cost_price` through the ONE shared definition
     * ({@see Product::resolveMovementUnitCost()}) so this writer, the batch
     * write-off and the POS return scrap can never value the same product
     * differently — the drift gate V10-I5 caught.
     *
     * Scoped by tenant + company: a forged/foreign `product_id` must not resolve.
     *
     * `withTrashed()` is load-bearing, not defensive (fix round 1, fiscal P2-1).
     * A POS sale is authored on the DEVICE and projected later — on sync, on a
     * retry, on a replay — and a product retired in between is a SOFT delete. Under
     * the default scope that retired product resolved to null, so the movement was
     * written and the stock decremented with `unit_cost = 0.000000` permanently;
     * the Wave-3 exit seam reads its COGS basis from that row, so the effect is an
     * understated COGS and an overstated margin whose only evidence is a log line.
     * A soft-deleted product is a resolvable HISTORICAL fact and its cost is the
     * cost that applied when the sale happened.
     *
     * A product that STILL cannot be resolved (a forged or cross-company
     * `product_id`) yields a ZERO cost rather than throwing: stock is a
     * best-effort downstream projection and a projector may never reject an
     * already-signed fiscal event (the doctrine at `applyScrapDisposition`). The
     * miss is logged so it is observable.
     *
     * @param  numeric-string  $quantity
     * @return array{unit_cost: numeric-string, total_cost: numeric-string}
     */
    private function movementCostSnapshot(
        string $tenantId,
        string $companyId,
        string $productId,
        string $quantity,
    ): array {
        $product = Product::query()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($productId);

        if ($product === null) {
            Log::warning('PosCoreReceiptProjection: product unresolvable for the movement cost snapshot; the movement will carry a zero cost', [
                'product_id' => $productId,
                'company_id' => $companyId,
            ]);

            /** @var numeric-string $zero */
            $zero = bcadd('0', '0', self::COST_SCALE);

            return ['unit_cost' => $zero, 'total_cost' => $zero];
        }

        /** @var numeric-string $rawUnitCost */
        $rawUnitCost = $product->resolveMovementUnitCost();
        /** @var numeric-string $unitCost */
        $unitCost = bcadd($rawUnitCost, '0', self::COST_SCALE);

        // The POS writers store a POSITIVE magnitude on both legs; take the
        // absolute value anyway so total_cost can never go negative if that
        // convention ever changes.
        $absoluteQuantity = bccomp($quantity, '0', 4) < 0
            ? bcmul($quantity, '-1', 4)
            : $quantity;

        /** @var numeric-string $absoluteQuantity */
        /** @var numeric-string $totalCost */
        $totalCost = bcmul($unitCost, $absoluteQuantity, self::COST_SCALE);

        return ['unit_cost' => $unitCost, 'total_cost' => $totalCost];
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
        string $currencyCode,
        CarbonInterface $entryDate,
        ?string $unitCost,
        ?string $variantId = null,
        ?CarbonInterface $occurredAt = null,
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

        $cost = $unitCost === null
            ? $this->movementCostSnapshot($tenantId, $companyId, $productId, $qty)
            : $this->movementCostFromUnitCost($unitCost, $qty);

        $movementOccurredAt = $occurredAt ?? now();
        $movement = StockMovement::query()->create([
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
            // DPA Wave 3 T5 — written by the SAME idempotent insert as the
            // movement, never a follow-up UPDATE: the projection is replayed on
            // retry and a second write would double-count.
            // avg_cost_before / avg_cost_after stay NULL: the POS path does not
            // re-average (claiming it did would be the V8 mistake).
            'unit_cost' => $cost['unit_cost'],
            'total_cost' => $cost['total_cost'],
            'reference' => 'POS Fiscal Event Projection',
            'reference_type' => 'pos_receipt',
            'reference_id' => $receiptId,
            'notes' => "Stock issued via PosCoreReceiptProjection (receipt: {$receiptId})",
            'user_id' => $cashierId,
            // Every projection row is server-created after deploy. Device time
            // belongs only in occurred_at and never suppresses queued COGS.
            'is_historical' => false,
            'occurred_at' => $movementOccurredAt,
        ]);

        $this->enqueuePosMovement($movement, MovementGlKind::Exit, $currencyCode, $entryDate, $cashierId);
    }

    /**
     * Stock restock for the lines of a REFUND/VOID receipt — the mirror of
     * `decrementStockForLines`. A refund/void returns goods to the shelf, so it
     * ADDS each line's positive magnitude back to the same grain the original
     * sale decremented (variant-scoped when the line carries a `variant_id`,
     * product-level otherwise). Same non-UUID-product skip as the decrement
     * path (the canonical snapshot stays authoritative; stock is a best-effort
     * downstream projection).
     */
    /**
     * v3-refund-chain-integration spec §10 — disposition-aware stock
     * restore.
     *
     * A v4 REFUND carries `original_line_references[i].disposition`
     * (strict parallel to `line_items[i]`, validated by
     * `FiscalPayloadConstraintValidator`); a legacy VOID or a pre-v4
     * REFUND has no disposition data on the canonical view at all
     * (`$view->originalLineReferences()` is null) — that path keeps its
     * EXISTING unconditional-restock behavior UNCHANGED (v2/v3/void
     * non-regression).
     *
     *   - `restock` — restores stock, UNLESS the product's own
     *     `RestockPolicyResolver` resolves `RestockPolicy::Never`
     *     ("regulated never-restock honored" — a projector can never
     *     REJECT an already-signed event (Model 1, §4.1), so a
     *     regulated/controlled item's disposition=restock is silently
     *     NOT applied rather than corrupting sellable stock; logged for
     *     operator visibility).
     *   - `not_received` — no stock movement at all (the goods never came
     *     back, so there is nothing to restore and nothing to destroy).
     *   - `scrap` — the SAME two-leg pair the interactive server return path
     *     writes (DPA V10): restore (`+qty`, `pos_return`) then a COST-BEARING
     *     write-off (`−qty`, `write_off`, Dr Shrinkage / Cr Inventory keyed on the
     *     movement) via `ReturnScrapWriteOffService`. Net sellable quantity is
     *     unchanged — exactly as when this branch skipped entirely — but the
     *     destruction is now a costed, GL-posted act instead of an invisible
     *     one. `RestockPolicy::Never` is deliberately NOT consulted here: a
     *     regulated never-restock item being DESTROYED is the correct outcome,
     *     and the pair never leaves it sellable.
     *
     * @param  array<int, bool>  $lineStockMovementExpected
     */
    private function restockForLines(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        string $currencyCode,
        CarbonInterface $entryDate,
        ?string $originalReceiptId,
        array $lineStockMovementExpected,
    ): void {
        $originalLineReferences = $view->originalLineReferences();

        foreach ($view->lineItems as $index => $line) {
            $productId = $line->productId;
            if ($productId === '' || ! Str::isUuid($productId)) {
                continue;
            }

            // R-1 adopts the original-sale basis: a refund reverses the cost
            // persisted by the sale projection even if live WAC has moved.
            $originalSale = $originalReceiptId === null
                ? ['unit_cost' => null, 'is_historical' => false]
                : $this->originalPosSaleBasis(
                    $event->tenant_id,
                    $event->company_id,
                    $originalReceiptId,
                    $productId,
                    $line->variantId,
                );

            if ($originalLineReferences !== null) {
                $reference = $originalLineReferences[$index] ?? null;
                if ($reference !== null) {
                    $disposition = ReturnLineDisposition::tryFrom($reference->disposition);

                    if (! ($lineStockMovementExpected[$index] ?? true)) {
                        if ($disposition === ReturnLineDisposition::Restock
                            && $this->restockPolicyResolver->resolve($productId)->policy === RestockPolicy::Never) {
                            Log::warning('PosCoreReceiptProjection: regulated never-restock product refunded with disposition=restock; stock NOT restored', [
                                'fiscal_event_id' => $event->id,
                                'receipt_id' => $receiptId,
                                'product_id' => $productId,
                            ]);
                        } elseif ($disposition === ReturnLineDisposition::Scrap
                            && Product::withTrashed()
                                ->where('tenant_id', $event->tenant_id)
                                ->where('company_id', $event->company_id)
                                ->whereKey($productId)
                                ->whereNotNull('deleted_at')
                                ->exists()) {
                            Log::warning('PosCoreReceiptProjection: scrap refund references an archived product; neither stock leg was recorded', [
                                'fiscal_event_id' => $event->id,
                                'receipt_id' => $receiptId,
                                'product_id' => $productId,
                            ]);
                        }

                        continue;
                    }

                    if ($disposition === ReturnLineDisposition::Scrap) {
                        $this->applyScrapDisposition(
                            receiptId: $receiptId,
                            event: $event,
                            terminal: $terminal,
                            view: $view,
                            productId: $productId,
                            quantity: $line->quantity,
                            variantId: $line->variantId,
                            entryDate: $entryDate,
                            unitCost: $originalSale['unit_cost'],
                            isHistorical: $originalSale['is_historical'],
                        );

                        continue;
                    }

                }
            }

            $this->restockStock(
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                locationId: (string) $terminal->location_id,
                productId: $productId,
                quantity: $line->quantity,
                receiptId: $receiptId,
                cashierId: $event->operator_id,
                // Same variant grain the sale decremented — a variant refund
                // restocks the variant row, a non-variant refund the
                // product-level row.
                variantId: $line->variantId,
                // occurred_at = DEVICE event time of the refund/void event.
                occurredAt: $event->event_time_device,
                currencyCode: $currencyCode,
                entryDate: $entryDate,
                unitCost: $originalSale['unit_cost'],
                isHistorical: $originalSale['is_historical'],
            );
        }
    }

    /**
     * SCRAP disposition on a v4 REFUND line — DPA V10.
     *
     * Writes the SAME two-leg pair as the interactive server return path
     * (`ReceiptReturnService`): the goods come back (`restockStock`, `+qty`),
     * then they are destroyed by a cost-bearing, GL-posted write-off
     * (`ReturnScrapWriteOffService`, `−qty`). Net sellable quantity is
     * unchanged, so this is a pure ADDITION of ledger truth on top of the
     * previous "skip entirely" behavior — no stock figure moves that did not
     * move before.
     *
     * **Replay safety.** `apply()` is guarded by the `pos_receipts.fiscal_event_id`
     * idempotency probe, so a re-projected event never reaches this method a
     * second time; the write-off journal entry is additionally keyed on the
     * movement id, so it cannot duplicate independently.
     *
     * **A projector may never REJECT an already-signed event** (Model 1, §4.1).
     * The pair therefore runs inside its own SAVEPOINT: if either leg throws
     * (archived product, variant-grain mismatch, insufficient available
     * quantity), BOTH legs roll back and the line falls back to the previous
     * net-zero / no-movement outcome, logged for operator follow-up, rather
     * than leaving the restore leg stranded as a phantom `+qty`. The write-off
     * service THROWS rather than declining quietly precisely so this savepoint
     * gets the chance to undo the restore leg (gate C1).
     *
     * **Retryable concurrency faults are the ONE thing NOT contained** (gate C2).
     * Laravel does not issue `ROLLBACK TO SAVEPOINT` for a nested deadlock /
     * serialization failure, so swallowing one would leave the enclosing
     * PostgreSQL transaction aborted (25P02) and let `apply()` "COMMIT" a
     * silently rolled-back receipt — the whole projection lost while its
     * projection row says applied and the event is never retried. Both legs take
     * `lockForUpdate` on `stock_levels`, so this block is genuinely
     * contention-prone. See {@see ConcurrencyFault}.
     *
     * Rule 20: no `CompanyContext` is touched — the currency comes from the
     * canonical payload and is passed explicitly.
     */
    private function applyScrapDisposition(
        string $receiptId,
        FiscalEvent $event,
        Terminal $terminal,
        SaleReceiptCanonicalView $view,
        string $productId,
        string $quantity,
        ?string $variantId,
        CarbonInterface $entryDate,
        ?string $unitCost,
        bool $isHistorical,
    ): void {
        // Canonical `line_items[].quantity` is a positive decimal magnitude
        // (enforced by FiscalPayloadConstraintValidator's quantity regex) —
        // same narrowing `restockStock` applies to the identical value.
        /** @var numeric-string $qty */
        $qty = $quantity;

        // No `stock_levels` row at this grain (service / non-inventory item, or a
        // variant grain that was never stocked): both legs are no-ops by
        // construction — `restockStock` logs and returns, and there is nothing to
        // destroy. Bail BEFORE the savepoint so this benign, recurring case does
        // not emit an ERROR on every occurrence (gate M2 log-noise), and so
        // `issue()` never materialises a zero-quantity row just to fail on it.
        if (! $this->stockGrainExists($event->company_id, (string) $terminal->location_id, $productId, $variantId)) {
            Log::warning('PosCoreReceiptProjection: no stock_levels row for a scrap line; neither leg recorded', [
                'fiscal_event_id' => $event->id,
                'receipt_id' => $receiptId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $terminal->location_id,
            ]);

            return;
        }

        $marker = $this->glBuffer->mark();

        try {
            DB::transaction(function () use ($receiptId, $event, $terminal, $view, $productId, $quantity, $qty, $variantId, $entryDate, $unitCost, $isHistorical): void {
                $this->restockStock(
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    locationId: (string) $terminal->location_id,
                    productId: $productId,
                    quantity: $quantity,
                    receiptId: $receiptId,
                    cashierId: $event->operator_id,
                    variantId: $variantId,
                    occurredAt: $event->event_time_device,
                    currencyCode: $view->payload->currencyCode,
                    entryDate: $entryDate,
                    unitCost: $unitCost,
                    isHistorical: $isHistorical,
                );

                $this->returnScrapWriteOffService->writeOff(
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    locationId: (string) $terminal->location_id,
                    productId: $productId,
                    quantity: $qty,
                    returnReceiptId: $receiptId,
                    returnReceiptNumber: $view->payload->receiptUuid,
                    currencyCode: $view->payload->currencyCode,
                    cashierId: $event->operator_id,
                    variantId: $variantId,
                    occurredAt: $event->event_time_device,
                    entryDate: $entryDate,
                    unitCost: $unitCost,
                    isHistorical: $isHistorical,
                );
            });
        } catch (\Throwable $e) {
            $this->glBuffer->rollbackTo($marker);

            // Gate C2: a retryable concurrency fault is NOT the projector
            // rejecting a signed event — it is infrastructure, and the job's own
            // retry is the correct handling. Swallowing it silently loses the
            // whole receipt (see the method docblock).
            if (ConcurrencyFault::isRetryable($e)) {
                throw $e;
            }

            Log::error('PosCoreReceiptProjection: scrap-disposition write-off failed; both legs rolled back, no stock movement recorded for this line', [
                'fiscal_event_id' => $event->id,
                'receipt_id' => $receiptId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Does a `stock_levels` row exist at the exact grain the projected stock
     * operation would touch? This unlocked snapshot classifies the immutable
     * detector outcome; the sale writer still performs its locked lookup so a
     * concurrently-created grain is not skipped.
     */
    private function stockGrainExists(
        string $companyId,
        string $locationId,
        string $productId,
        ?string $variantId,
    ): bool {
        return StockLevel::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->when(
                $variantId !== null,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->whereNull('variant_id'),
            )
            ->exists();
    }

    /**
     * Restock one product line for a REFUND/VOID — the mirror of
     * `decrementStock`. Adds the positive line quantity back to the grain the
     * sale decremented and records a `MovementType::Receipt` /
     * `MovementReason::POSReturn` movement.
     *
     * Same grain discipline as `decrementStock`: a variant line with no
     * variant-scoped `stock_levels` row must NOT restock the product-level pool
     * (the symmetric counterpart of the pre-T2 fallback bug). Nothing is
     * restocked and the absent variant grain is surfaced; a product-level line
     * with no row (non-inventory / service item) stays silent.
     */
    private function restockStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $cashierId,
        string $currencyCode,
        CarbonInterface $entryDate,
        bool $isHistorical,
        ?string $variantId = null,
        ?CarbonInterface $occurredAt = null,
        ?string $unitCost = null,
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
            if ($variantId !== null) {
                Log::warning('PosCoreReceiptProjection: variant refund/void found no variant-scoped stock_levels row; nothing restocked', [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'receipt_id' => $receiptId,
                ]);
            }

            return;
        }

        /** @var numeric-string $stockQty */
        $stockQty = $stockLevel->quantity;
        $quantityBefore = $stockQty;
        /** @var numeric-string $qty */
        $qty = $quantity;
        // stock_levels.quantity and the canonical line quantity are stored at
        // scale 4 (canonical quantity storage scale). Add at scale 4 so
        // sub-centi quantities are not truncated to zero.
        $quantityAfter = bcadd($stockQty, $qty, 4); // 4 = canonical quantity storage scale

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        $cost = $unitCost === null
            ? $this->movementCostSnapshot($tenantId, $companyId, $productId, $qty)
            : $this->movementCostFromUnitCost($unitCost, $qty);

        $movementOccurredAt = $occurredAt ?? now();
        $movement = StockMovement::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::POSReturn,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            // DPA Wave 3 T5 — see decrementStock(). Same definition, same scale.
            'unit_cost' => $cost['unit_cost'],
            'total_cost' => $cost['total_cost'],
            'reference' => 'POS Fiscal Event Projection (refund/void restock)',
            'reference_type' => 'pos_receipt',
            'reference_id' => $receiptId,
            'notes' => "Stock restocked via PosCoreReceiptProjection refund/void (receipt: {$receiptId})",
            'user_id' => $cashierId,
            'is_historical' => $isHistorical,
            'occurred_at' => $movementOccurredAt,
        ]);

        $this->enqueuePosMovement($movement, MovementGlKind::Entry, $currencyCode, $entryDate, $cashierId);
    }

    /**
     * Resolve the immutable cost captured by the original POS sale movement.
     * Duplicate product lines share the same sale-time snapshot, so product +
     * variant grain is sufficient even though movements do not carry line ids.
     */
    /** @return array{unit_cost: ?string, is_historical: bool} */
    private function originalPosSaleBasis(
        string $tenantId,
        string $companyId,
        string $originalReceiptId,
        string $productId,
        ?string $variantId,
    ): array {
        $query = StockMovement::query()
            ->select('stock_movements.*')
            ->selectRaw('CASE WHEN stock_movements.created_at < companies.inventory_gl_cutover_at THEN 1 ELSE 0 END AS gl_is_historical')
            ->join('companies', 'companies.id', '=', 'stock_movements.company_id')
            ->where('stock_movements.tenant_id', $tenantId)
            ->where('stock_movements.company_id', $companyId)
            ->where('stock_movements.reference_type', 'pos_receipt')
            ->where('stock_movements.reference_id', $originalReceiptId)
            ->where('stock_movements.product_id', $productId)
            ->where('stock_movements.reason', MovementReason::POSSale);

        $variantId === null
            ? $query->whereNull('stock_movements.variant_id')
            : $query->where('stock_movements.variant_id', $variantId);

        $movement = $query
            ->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.id')
            ->first();
        if ($movement === null) {
            Log::warning('PosCoreReceiptProjection: original POS sale cost was unavailable; refund falls back to current cost', [
                'original_receipt_id' => $originalReceiptId,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ]);

            return ['unit_cost' => null, 'is_historical' => false];
        }

        $isHistorical = (int) $movement->getAttribute('gl_is_historical') === 1;
        if ($movement->unit_cost === null) {
            Log::warning('PosCoreReceiptProjection: original POS sale cost was unavailable; refund falls back to current cost', [
                'original_receipt_id' => $originalReceiptId,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ]);

            return ['unit_cost' => null, 'is_historical' => $isHistorical];
        }

        return [
            'unit_cost' => (string) $movement->unit_cost,
            // Compare in PostgreSQL because created_at is timestamp while the
            // company watermark is timestamptz; PHP casts erase that distinction.
            'is_historical' => $isHistorical,
        ];
    }

    /**
     * @param  numeric-string  $quantity
     * @return array{unit_cost: numeric-string, total_cost: numeric-string}
     */
    private function movementCostFromUnitCost(string $unitCost, string $quantity): array
    {
        if (! is_numeric($unitCost)) {
            throw new \LogicException('Original POS movement unit_cost must be numeric.');
        }

        /** @var numeric-string $normalizedUnitCost */
        $normalizedUnitCost = bcadd($unitCost, '0', self::COST_SCALE);
        /** @var numeric-string $absoluteQuantity */
        $absoluteQuantity = bccomp($quantity, '0', 4) < 0
            ? bcmul($quantity, '-1', 4)
            : $quantity;
        /** @var numeric-string $totalCost */
        $totalCost = bcmul($normalizedUnitCost, $absoluteQuantity, self::COST_SCALE);

        return ['unit_cost' => $normalizedUnitCost, 'total_cost' => $totalCost];
    }

    private function enqueuePosMovement(
        StockMovement $movement,
        MovementGlKind $kind,
        string $currencyCode,
        CarbonInterface $entryDate,
        string $cashierId,
    ): void {
        $occurredAt = $movement->occurred_at ?? $movement->created_at ?? now();
        $this->glBuffer->enqueue(new MovementGlContext(
            kind: $kind,
            movementId: $movement->id,
            companyId: $movement->company_id,
            currencyCode: $currencyCode,
            reason: $movement->reason ?? throw new \LogicException('POS movement is missing its GL reason.'),
            quantityBefore: (string) $movement->quantity_before,
            quantityAfter: (string) $movement->quantity_after,
            unitCost: (string) ($movement->unit_cost ?? '0'),
            sourceType: $movement->reference_type,
            sourceId: $movement->reference_id,
            occurredAt: \DateTimeImmutable::createFromInterface($occurredAt),
            entryDate: \DateTimeImmutable::createFromInterface($entryDate),
            postedByUserId: $cashierId,
            isHistorical: (bool) $movement->is_historical,
        ));
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
     * Normalize a denomination to the decimal(15,4) storage scale.
     *
     * Deliberately separate from `normalize()`: the denomination is NOT a
     * currency amount, it is a policy value stored at the same scale as
     * `country_payment_settings.cash_rounding_denomination`, and rounding it
     * to the scale-3 money scale would make the policy comparison lossy.
     *
     * @return numeric-string
     */
    private function normalizeDenomination(string $value): string
    {
        /** @var numeric-string $value */
        /** @var numeric-string $normalized */
        $normalized = bcadd($value, '0', self::DENOMINATION_SCALE);

        return $normalized;
    }

    /**
     * Run the policy reconciliation inside a SAVEPOINT so a telemetry failure
     * can never cost the sale.
     *
     * This is called from inside `apply()`'s `DB::transaction`, after the
     * receipt row and all its children are already written. An uncontained
     * throw here would roll back the ENTIRE projection — and because the
     * inputs are identical on every attempt, the retry fails the same way and
     * the event dead-letters. A receipt would be lost to a *warning*.
     *
     * The nested `DB::transaction()` is load-bearing and is NOT
     * interchangeable with a bare try/catch: on PostgreSQL a failed statement
     * aborts the whole transaction (`SQLSTATE 25P02` on everything that
     * follows), so swallowing the exception without rolling back to a
     * savepoint would poison the outer transaction and fail the commit anyway.
     * Task 7's savepointed `VALIDATE CONSTRAINT` uses the same construct for
     * the same reason. (`earnLoyaltyPoints()` above catches without a
     * savepoint and is the reason `PosCoreReceiptProjectionLoyaltyEarnTest`
     * dies with `25P02` under PostgreSQL — the norm this mirrors is its
     * try/catch containment, deliberately not its missing savepoint.)
     *
     * @param  numeric-string  $signedDenomination
     */
    private function reconcileRoundingPolicySafely(FiscalEvent $event, string $signedDenomination): void
    {
        try {
            DB::transaction(function () use ($event, $signedDenomination): void {
                $this->reconcileRoundingPolicy($event, $signedDenomination);
            });
        } catch (\Throwable $e) {
            Log::warning('PosCoreReceiptProjection: rounding-policy reconciliation failed (sale unaffected)', [
                'fiscal_event_id' => $event->id,
                'signed_denomination' => $signedDenomination,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flag a projected receipt whose SIGNED denomination no longer matches the
     * live EFFECTIVE policy (spec §4.5). The receipt still projects — the
     * signature is the fiscal authority; this is drift telemetry, not a gate.
     *
     * The comparison target is `PosPaymentPolicyResolver::forCompany()`, NOT
     * the raw `country_payment_settings.cash_rounding_denomination` column.
     * Reading the column directly misses the single most important drift case:
     * migration A2 seeds TN with `cash_rounding_enabled = false` AND a
     * denomination of `0.0500`, so an operator who switches rounding OFF while
     * a stale terminal keeps signing rounded receipts would compare 0.0500
     * against 0.0500 and stay silent — exactly the incident this telemetry
     * exists to surface. The resolver is also the same fail-closed gate that
     * decided what the device was allowed to cache (round-trip validity,
     * non-positive values, the §4.1 `CashRoundingCaps` ceiling), so anything
     * it refuses to emit is by definition not the live policy. Rounding being
     * disabled is therefore a mismatch, not an exemption.
     *
     * Worker-safe by construction: the resolver takes an explicit company id
     * and never touches `CompanyContext` or a no-arg `getScale()` (rule 20).
     *
     * NEVER string-compare here. The resolver emits at the COMPANY CURRENCY
     * scale (`0.050` for TND) while the signed value is normalized to the
     * decimal(15,4) storage scale (`0.0500`), so string equality would
     * false-alarm on every single rounded receipt. `bccomp` at
     * `DENOMINATION_SCALE` is the only correct comparison.
     *
     * The `audit_events` probe keeps the alert single-shot: the receipt insert
     * is guarded by `insertReceiptOnConflictDoNothing`, but a caller that
     * reaches this method twice for one event must not stack duplicates.
     *
     * @param  numeric-string  $signedDenomination
     */
    private function reconcileRoundingPolicy(FiscalEvent $event, string $signedDenomination): void
    {
        $policy = $this->posPaymentPolicyResolver->forCompany($event->company_id);

        $matches = $policy->cashRoundingEnabled
            && is_numeric($policy->cashRoundingDenomination)
            && bccomp($signedDenomination, $policy->cashRoundingDenomination, self::DENOMINATION_SCALE) === 0;

        if ($matches) {
            return;
        }

        $alreadyRecorded = DB::table('audit_events')
            ->where('tenant_id', $event->tenant_id)
            ->where('event_type', 'pos.rounding.policy_mismatch')
            ->where('aggregate_type', 'fiscal_event')
            ->where('aggregate_id', $event->id)
            ->exists();

        if (! $alreadyRecorded) {
            $this->auditService->record(
                companyId: $event->company_id,
                userId: $event->operator_id,
                eventType: 'pos.rounding.policy_mismatch',
                aggregateType: 'fiscal_event',
                aggregateId: $event->id,
                payload: [
                    'fiscal_event_id' => $event->id,
                    'signed_denomination' => $signedDenomination,
                    'policy_rounding_enabled' => $policy->cashRoundingEnabled,
                    'policy_denomination' => $policy->cashRoundingDenomination,
                    'currency_code' => $policy->currencyCode,
                ],
            );
        }

        Log::warning('POS receipt signed a rounding denomination that no longer matches live policy.', [
            'fiscal_event_id' => $event->id,
            'signed_denomination' => $signedDenomination,
            'policy_rounding_enabled' => $policy->cashRoundingEnabled,
            'policy_denomination' => $policy->cashRoundingDenomination,
        ]);
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
                $event->company_id,
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
