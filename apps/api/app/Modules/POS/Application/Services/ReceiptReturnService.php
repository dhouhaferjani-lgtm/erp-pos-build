<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Concerns\RoundsVat;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\DailyRefundCapExceededException;
use App\Modules\POS\Domain\Exceptions\ManagerOverrideRequiredException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\ReceiptHashService;
use App\Modules\POS\Domain\Services\RefundDestinationResolver;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Application\Services\RestockPolicyResolver;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Product;
use App\Modules\Treasury\Application\DTOs\RefundAllocation;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Voucher;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\ConcurrencyFault;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\TransactionRemiseSplit;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates partial/full POS returns (Phase E refactor).
 *
 * Pipeline (all inside a single DB transaction):
 *  1. DB-level idempotency — return existing receipt if refund_request_id seen
 *     before (cheap unlocked check; re-checked authoritatively in step 2 after
 *     the original-receipt lock, with a unique-index catch backstop around the
 *     whole transaction for anything that still slips through).
 *  2. Load + validate original receipt.
 *  3. Validate return quantities.
 *  4. Compute totals.
 *  5. Enforce return window, daily cap, manager-override threshold.
 *  6. Resolve refund destination via RefundDestinationResolver.
 *  7. Build pending_seal return-receipt draft (no inline hash, no chain advance).
 *  8. Execute destination-specific side effects:
 *       StoreVoucher → VoucherIssuanceService::issueFromRefund() (BEFORE finalization)
 *       OriginalPayment → PaymentRefundService::refundReceiptPayments()
 *       Cash → CashDrawerService::recordRefund()
 *  9. Restore stock.
 * 10. Seal the draft via ReceiptFinalizationService::finalize().
 *
 * Backward compatibility: when $destination is null (legacy callers / existing tests),
 * the pipeline defaults to Cash behaviour — same observable contract as before Phase E.
 */
final class ReceiptReturnService
{
    /**
     * Precision of the pro-rata share in the D-1 sealed-reversal arm, above the
     * currency scale. Matches `TransactionRemiseSplit::RATIO_EXTRA_SCALE` so the
     * two D-1 arithmetics carry their intermediates at the same width.
     */
    private const REVERSAL_RATIO_EXTRA_SCALE = TransactionRemiseSplit::RATIO_EXTRA_SCALE;

    use RoundsVat;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CashDrawerService $cashDrawerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ReceiptFinalizationService $finalizationService,
        private readonly RefundDestinationResolver $destinationResolver,
        private readonly VoucherIssuanceService $voucherIssuanceService,
        private readonly PaymentRefundService $paymentRefundService,
        private readonly ReceiptHashService $receiptHashService,
        private readonly RestockPolicyResolver $restockPolicyResolver,
        private readonly LegacyCorrectionGuard $legacyCorrectionGuard,
        private readonly ReturnScrapWriteOffService $returnScrapWriteOffService,
        private readonly InventoryGlPostingBuffer $glBuffer,
        private readonly FEFOInventoryService $fefoService,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Process a partial or full return on a receipt.
     *
     * @param  string  $originalReceiptId  UUID of the original receipt
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines  Lines to return with quantities
     * @param  ReturnReason  $returnReason  Reason for the return
     * @param  User  $cashier  The cashier processing the return
     * @param  string  $terminalId  The terminal processing the return
     * @param  string|null  $notes  Optional notes
     * @param  RefundDestination|null  $destination  Refund destination (null = Cash for backward compat)
     * @param  string|null  $refundRequestId  Client-supplied idempotency UUID
     * @param  string|null  $authorizedByUserId  Manager UUID when an override fires
     * @param  string|null  $overrideReason  Reason text for the manager override
     * @param  string|null  $exchangeGroupId  Exchange group UUID shared with the paired sale receipt (Phase F)
     * @return Receipt The created return receipt with relationships loaded
     *
     * @throws \RuntimeException If receipt cannot be returned
     * @throws \InvalidArgumentException If return data is invalid
     * @throws ManagerOverrideRequiredException If refund exceeds manager-override threshold and no authorizer supplied
     * @throws DailyRefundCapExceededException If cashier's daily cap would be exceeded
     */
    public function processReturn(
        string $originalReceiptId,
        array $returnLines,
        ReturnReason $returnReason,
        User $cashier,
        string $terminalId,
        ?string $notes = null,
        ?RefundDestination $destination = null,
        ?string $refundRequestId = null,
        ?string $authorizedByUserId = null,
        ?string $overrideReason = null,
        ?string $exchangeGroupId = null,
    ): Receipt {
        if (count($returnLines) === 0) {
            throw new \InvalidArgumentException('At least one line item is required for a return');
        }

        $companyId = $this->companyContext->requireCompanyId();

        try {
            return $this->runReturnTransaction(
                $originalReceiptId,
                $returnLines,
                $returnReason,
                $cashier,
                $terminalId,
                $notes,
                $destination,
                $refundRequestId,
                $authorizedByUserId,
                $overrideReason,
                $companyId,
                $exchangeGroupId,
            );
        } catch (QueryException $exception) {
            // Last-resort idempotency backstop (PostgreSQL): if a concurrent
            // request slipped past both idempotency checks and our INSERT hit
            // the unique partial index on (company_id, refund_request_id),
            // replay its committed return receipt instead of bubbling a 500.
            // The transaction above has already rolled back at this point.
            if (
                $refundRequestId !== null
                && $this->isRefundRequestIdUniqueViolation($exception)
            ) {
                $existing = $this->findReturnByRefundRequestId($refundRequestId, $companyId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    /**
     * The single-transaction return pipeline (see processReturn for the contract).
     *
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines
     */
    private function runReturnTransaction(
        string $originalReceiptId,
        array $returnLines,
        ReturnReason $returnReason,
        User $cashier,
        string $terminalId,
        ?string $notes,
        ?RefundDestination $destination,
        ?string $refundRequestId,
        ?string $authorizedByUserId,
        ?string $overrideReason,
        string $companyId,
        ?string $exchangeGroupId,
    ): Receipt {
        return DB::transaction(function () use (
            $originalReceiptId,
            $returnLines,
            $returnReason,
            $cashier,
            $terminalId,
            $notes,
            $destination,
            $refundRequestId,
            $authorizedByUserId,
            $overrideReason,
            $companyId,
            $exchangeGroupId,
        ): Receipt {
            // ─────────────────────────────────────────────────────────────────
            // Step 1: DB-level idempotency — return existing receipt unchanged.
            // CHEAP UNLOCKED check only: a concurrent request holding the
            // original-receipt lock may commit the same refund_request_id
            // after this read. Step 2 re-checks AFTER acquiring the lock.
            // ─────────────────────────────────────────────────────────────────
            if ($refundRequestId !== null) {
                $existing = $this->findReturnByRefundRequestId($refundRequestId, $companyId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 2: Load and validate original receipt
            // ─────────────────────────────────────────────────────────────────
            /** @var Receipt $originalReceipt */
            $originalReceipt = Receipt::with(['lines', 'returnReceipts.lines'])
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($originalReceiptId);

            // AUTHORITATIVE idempotency re-check, now that the original
            // receipt row is locked. Concurrent requests with the same
            // refund_request_id target the same original receipt, so the lock
            // serializes us behind any winner: this re-read sees its committed
            // return receipt and replays it instead of inserting a duplicate
            // (which would violate the unique partial index → 500).
            if ($refundRequestId !== null) {
                $existing = $this->findReturnByRefundRequestId($refundRequestId, $companyId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $this->validateOriginalReceipt($originalReceipt);

            // ─────────────────────────────────────────────────────────────────
            // Step 3: Lock terminal + verify active shift
            // ─────────────────────────────────────────────────────────────────
            /** @var Terminal $terminal */
            $terminal = Terminal::where('company_id', $companyId)
                ->where('id', $terminalId)
                ->lockForUpdate()
                ->firstOrFail();

            // v3-refund-chain-integration spec §9.1/§9.3 — retires this
            // legacy authoring path for any terminal that has acknowledged
            // v4 refund authoring.
            $this->legacyCorrectionGuard->assertLegacyCorrectionAllowed($terminal);

            if (! $terminal->isActive()) {
                throw new \RuntimeException('Terminal is not active');
            }

            $shift = Shift::where('terminal_id', $terminal->id)
                ->where('status', ShiftStatus::Open)
                ->first();

            if ($shift === null) {
                throw new \RuntimeException('No active shift on this terminal. Open a shift first.');
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 4: Validate return quantities
            // ─────────────────────────────────────────────────────────────────
            $validatedLines = $this->validateReturnQuantities($originalReceipt, $returnLines);

            // ─────────────────────────────────────────────────────────────────
            // Step 5: Compute return totals
            // ─────────────────────────────────────────────────────────────────
            /** @var Company $company */
            $company = $terminal->company ?? Company::findOrFail($terminal->company_id);
            $currency = $company->currency ?? 'TND';
            $originalIsHistorical = $this->originalReceiptPredatesInventoryGlCutover($originalReceipt->id);

            [$receiptLines, $vatAggregates, $subtotal, $totalTax, $totalDiscount, $total] =
                $this->computeReturnTotals($validatedLines, $currency, $originalReceipt);

            // ─────────────────────────────────────────────────────────────────
            // Step 6: Policy guards (window / cap / manager-override threshold)
            // ─────────────────────────────────────────────────────────────────
            $policy = $company->getReservationSettings();
            // hasExplicitPolicy: true only when the company JSONB column is not NULL.
            // Guards that enforce new Phase E controls are skipped for companies that
            // have not yet configured their reservation_settings (backward compat).
            $hasExplicitPolicy = $company->reservation_settings !== null;
            $outOfWindow = $this->isOutOfWindow($originalReceipt, $policy);
            $cashierPermissions = $cashier->getAllPermissions()->pluck('name')->toArray();

            if ($hasExplicitPolicy) {
                $this->guardWindowAndCap(
                    $cashier,
                    $total,
                    $policy,
                    $outOfWindow,
                    $cashierPermissions,
                );
                $this->guardManagerOverride(
                    $total,
                    $policy,
                    $authorizedByUserId,
                );
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 7: Resolve refund destination
            // ─────────────────────────────────────────────────────────────────
            $resolvedDestination = $this->resolveDestination(
                $destination,
                $originalReceipt,
                $policy,
                $cashierPermissions,
            );

            // Determine policy_trigger based on what fired
            $policyTrigger = $this->resolvePolicyTrigger(
                $outOfWindow,
                $total,
                $policy,
                $authorizedByUserId,
            );

            // ─────────────────────────────────────────────────────────────────
            // Step 8: Build return-receipt draft (pending_seal)
            // ─────────────────────────────────────────────────────────────────
            $draft = $this->buildReturnDraft(
                originalReceipt: $originalReceipt,
                terminal: $terminal,
                cashier: $cashier,
                returnReason: $returnReason,
                vatAggregates: $vatAggregates,
                subtotal: $subtotal,
                totalTax: $totalTax,
                totalDiscount: $totalDiscount,
                total: $total,
                currency: $currency,
                notes: $notes,
                outOfWindow: $outOfWindow,
                policyTrigger: $policyTrigger,
                authorizedByUserId: $authorizedByUserId,
                overrideReason: $overrideReason,
                refundRequestId: $refundRequestId,
                exchangeGroupId: $exchangeGroupId,
            );

            // ─────────────────────────────────────────────────────────────────
            // Step 9: Save receipt lines + VAT details
            // ─────────────────────────────────────────────────────────────────
            foreach ($receiptLines as $lineData) {
                ReceiptLine::create(array_merge($lineData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $draft->id,
                ]));
            }

            foreach ($vatAggregates as $vatData) {
                ReceiptVatDetail::create(array_merge($vatData, [
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $draft->id,
                ]));
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 10: Destination-specific side effects
            // ─────────────────────────────────────────────────────────────────
            $absTotal = $this->absTotal($total);

            match ($resolvedDestination) {
                RefundDestination::StoreVoucher => $this->executeVoucherIssuance(
                    $draft,
                    $cashier,
                    $terminal,
                    $authorizedByUserId,
                    $overrideReason,
                    $policyTrigger,
                    $absTotal,
                    $currency,
                    $policy,
                ),
                RefundDestination::OriginalPayment => $this->executePaymentRefund(
                    $originalReceipt,
                    $absTotal,
                    $policy,
                    $refundRequestId,
                    $authorizedByUserId,
                    $policyTrigger,
                ),
                RefundDestination::Cash => $this->executeCashRefund(
                    $draft,
                    $total,
                    $cashier,
                    $shift,
                ),
                // ExchangeDeferred: no payment side effect on the return half.
                // Net settlement is handled externally by ExchangeService.
                RefundDestination::ExchangeDeferred => null,
            };

            // ─────────────────────────────────────────────────────────────────
            // Step 11: Restore stock (disposition-branched)
            //
            // NOT_RECEIVED → zero movements (goods never came back).
            // RESTOCK      → receive back (+qty), then batch restitution.
            // SCRAP        → receive back (+qty) then a COST-BEARING write-off
            //                (-qty, Dr Shrinkage / Cr Inventory keyed on the movement —
            //                DPA V10); net aggregate change = 0; batch restitution
            //                skipped (scrapped goods never re-enter a sellable batch).
            // ─────────────────────────────────────────────────────────────────
            foreach ($validatedLines as $returnLine) {
                /** @var ReceiptLine $originalLine */
                $originalLine = $returnLine['original_line'];

                if ($originalLine->product_id === null) {
                    continue;
                }

                /** @var ReturnLineDisposition $disposition */
                $disposition = $returnLine['disposition'];

                if ($disposition === ReturnLineDisposition::NotReceived) {
                    continue; // nothing came back — no aggregate, no batch movement
                }

                /** @var numeric-string $qty */
                $qty = $returnLine['quantity'];
                /** @var numeric-string $alreadyReturnedQty */
                $alreadyReturnedQty = $returnLine['already_returned'];
                $saleMovementBasis = $this->originalSaleMovementUnitCost(
                    tenantId: $terminal->tenant_id,
                    companyId: $companyId,
                    originalReceiptId: $originalReceipt->id,
                    productId: $originalLine->product_id,
                    variantId: $originalLine->variant_id,
                );
                $originalUnitCost = $saleMovementBasis['found']
                    ? $saleMovementBasis['unit_cost']
                    : $this->receiptLineUnitCost($originalLine);

                if ($disposition === ReturnLineDisposition::Scrap) {
                    // The scrap PAIR (receive back, then destroy) is ATOMIC and
                    // CONTAINED — see applyScrapPair.
                    $this->applyScrapPair(
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        locationId: $originalReceipt->location_id,
                        productId: $originalLine->product_id,
                        quantity: $qty,
                        returnReceiptId: $draft->id,
                        returnReceiptNumber: $draft->receipt_number,
                        currencyCode: $draft->currency,
                        cashierId: $cashier->id,
                        variantId: $originalLine->variant_id,
                        entryDate: $draft->posted_at,
                        unitCost: $originalUnitCost,
                        isHistorical: $originalIsHistorical,
                    );

                    continue;
                }

                // RESTOCK.
                //
                // Variant symmetry rule: the restore must target the exact
                // stock row the sale decremented. Both paths now decrement at
                // the line's grain — a VARIANT line (whether draft-path or
                // projection-path) decrements the variant row and its
                // pos_receipt_lines.variant_id is the resolved variant; only a
                // NON-variant line carries NULL (variant_id IS NULL decrement).
                // restoreStock targets $originalLine->variant_id verbatim, so
                // it is symmetric in both cases. Never re-derive the variant
                // for NULL lines.
                $restoreMovement = $this->restoreStock(
                    tenantId: $terminal->tenant_id,
                    companyId: $companyId,
                    locationId: $originalReceipt->location_id,
                    productId: $originalLine->product_id,
                    quantity: $qty,
                    returnReceiptId: $draft->id,
                    cashierId: $cashier->id,
                    variantId: $originalLine->variant_id,
                    currencyCode: $draft->currency,
                    entryDate: $draft->posted_at,
                    unitCost: $originalUnitCost,
                    isHistorical: $originalIsHistorical,
                );

                // 🚨 Gate r4 R4-6/R4-7 + gate r5 R5-1 — ONE restore service, TWO
                // inputs: per-line PROVENANCE first, heuristic only as a fallback.
                //
                // r4 converged both channels on the one Domain service, which was
                // right — the old snapshot-only arm restored NOTHING when the
                // snapshot was absent, exactly the shape a COMPOSITE leaf leaves.
                // But it converged onto the WEAKER policy: it deleted the per-line
                // `pos_receipt_line_batch_allocations` snapshot, which names
                // exactly which lots THIS line consumed, in favour of a ledger scan
                // aggregated over the whole (product, location, variant) tuple. The
                // ledger cannot tell which RECEIPT shipped a leg, so the heuristic
                // is only right when the returned sale was the last one out.
                //
                // Gate r5 measured the regression: lot A (short-dated) and lot B
                // (long-dated); receipt 1 ships A, receipt 2 ships B; returning
                // receipt 1 credited **B** and left A at zero with 5 of its units
                // back on the shelf. `Σ lots == stock_levels` still reconciles,
                // which is why nothing else caught it — but two lot balances are
                // wrong at rest, `BatchTraceabilityController` (which still reads
                // these allocation rows) reports the recall wrong in both
                // directions, and short-dated units get a longer expiry so FEFO
                // ships them LAST.
                //
                // The snapshot is therefore an ORIGIN HINT, not a replacement
                // policy: it is applied first, still capped by each lot's
                // outstanding outbound, and whatever it does not cover falls
                // through to the heuristic and then to the DEFAULT lot. Composite
                // leaves — which have no snapshot — are unaffected and still reach
                // the fallback, which is what R4-7 was actually asking for.
                if ($restoreMovement !== null && $this->fefoService->productRequiresBatchTracking($originalLine->product_id)) {
                    $this->fefoService->restoreBatchesForReturn(
                        tenantId: $terminal->tenant_id,
                        companyId: $companyId,
                        productId: $originalLine->product_id,
                        locationId: $originalReceipt->location_id,
                        quantity: $qty,
                        movementId: $restoreMovement->id,
                        variantId: $originalLine->variant_id,
                        preferredLots: $this->lotProvenanceForLine($originalLine, $alreadyReturnedQty),
                    );
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 12: Seal via ReceiptFinalizationService (chain advance happens here)
            // ─────────────────────────────────────────────────────────────────
            $sealed = $this->finalizationService->finalize($draft);

            try {
                $this->glBuffer->flushIfOutermost(contained: true);
            } catch (\Throwable $e) {
                if (ConcurrencyFault::isRetryable($e)) {
                    throw $e;
                }

                Log::error('ReceiptReturnService: inventory GL batch failed; every inventory entry for the return was discarded', [
                    'return_receipt_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
            }

            /** @var Receipt */
            return $sealed->fresh([
                'lines',
                'vatDetails',
                'terminal',
                'cashier',
                'originalReceipt',
            ]);
        });
    }

    // =========================================================================
    // Idempotency (refund_request_id)
    // =========================================================================

    /**
     * Look up an already-committed return receipt for an idempotency key,
     * with the same relationship set processReturn returns.
     */
    private function findReturnByRefundRequestId(string $refundRequestId, string $companyId): ?Receipt
    {
        /** @var Receipt|null $existing */
        $existing = Receipt::where('refund_request_id', $refundRequestId)
            ->where('company_id', $companyId)
            ->first();

        if ($existing === null) {
            return null;
        }

        /** @var Receipt */
        return $existing->fresh([
            'lines',
            'vatDetails',
            'terminal',
            'cashier',
            'originalReceipt',
        ]);
    }

    /**
     * Whether a QueryException is a unique-key violation on the
     * (company_id, refund_request_id) partial index — i.e. a concurrent
     * request committed the same idempotency key first.
     */
    private function isRefundRequestIdUniqueViolation(QueryException $exception): bool
    {
        // SQLSTATE 23505 = PostgreSQL unique_violation; 23000 covers the
        // SQLite/ANSI integrity-constraint class used in the test suite.
        $sqlState = $exception->errorInfo[0] ?? null;

        if ($sqlState !== '23505' && $sqlState !== '23000') {
            return false;
        }

        return str_contains($exception->getMessage(), 'refund_request_id');
    }

    // =========================================================================
    // Policy guards
    // =========================================================================

    private function isOutOfWindow(Receipt $original, ReservationSettings $policy): bool
    {
        $windowEndsAt = $original->posted_at->copy()->addDays($policy->customerReturnExpiryDays);

        return Carbon::now()->isAfter($windowEndsAt);
    }

    /**
     * Guard daily refund cap.
     *
     * @param  numeric-string  $returnTotal  The negative return total (absolute value used for cap check)
     * @param  array<string>  $cashierPermissions
     *
     * @throws DailyRefundCapExceededException
     */
    private function guardWindowAndCap(
        User $cashier,
        string $returnTotal,
        ReservationSettings $policy,
        bool $outOfWindow,
        array $cashierPermissions,
    ): void {
        if ($policy->dailyRefundCapPerCashier === null) {
            return;
        }

        /** @var numeric-string $cap */
        $cap = $policy->dailyRefundCapPerCashier;
        $scale = $this->scale();

        // Query today's total refunds (absolute) for this cashier
        /** @var numeric-string $todayTotal */
        $todayTotal = (string) Receipt::where('cashier_id', $cashier->id)
            ->where('receipt_type', ReceiptType::Return->value)
            ->where('is_voided', false)
            ->whereDate('posted_at', Carbon::today())
            ->sum(DB::raw('ABS(total)'));

        $absAmount = $this->absTotal($returnTotal);
        /** @var numeric-string $projected */
        $projected = bcadd($todayTotal, $absAmount, $scale);

        if (bccomp($projected, $cap, $scale) > 0) {
            $hasOverridePerm = in_array('pos.refund_extend_daily_cap', $cashierPermissions, true);

            if (! $hasOverridePerm || ! $policy->dailyRefundCapOverrideAllowed) {
                throw new DailyRefundCapExceededException($cap, $projected, $cashier->id);
            }
        }
    }

    /**
     * Guard manager-override threshold.
     *
     * Effective threshold = max(flat_amount, percent_of_original_total).
     * When the return amount exceeds the threshold and no authorized_by_user_id was supplied, throw.
     *
     * @param  numeric-string  $returnTotal  Negative return total; absolute value used for comparison
     *
     * @throws ManagerOverrideRequiredException
     */
    private function guardManagerOverride(
        string $returnTotal,
        ReservationSettings $policy,
        ?string $authorizedByUserId,
    ): void {
        $scale = $this->scale();
        $absAmount = $this->absTotal($returnTotal);

        /** @var numeric-string $flatThreshold */
        $flatThreshold = $policy->managerOverrideThresholdAmount;

        // The percent threshold is absolute (e.g. "10.00" means refund > 10% of original).
        // We do not have the original total here, so we compare against the flat threshold only.
        // The percent-of-original logic can be wired in Phase H when the original is passed.
        if (bccomp($absAmount, $flatThreshold, $scale) > 0 && $authorizedByUserId === null) {
            throw new ManagerOverrideRequiredException($absAmount, $flatThreshold);
        }
    }

    /**
     * Resolve the refund destination via RefundDestinationResolver.
     *
     * When $requested is null (legacy / unspecified), default to Cash to preserve
     * backward-compatible behaviour (original service always did a cash-drawer refund).
     *
     * ExchangeDeferred bypasses the policy resolver: it is a service-internal sentinel
     * used by ExchangeService to indicate that net settlement happens externally.
     *
     * @param  array<string>  $cashierPermissions
     */
    private function resolveDestination(
        ?RefundDestination $requested,
        Receipt $original,
        ReservationSettings $policy,
        array $cashierPermissions,
    ): RefundDestination {
        $effective = $requested ?? RefundDestination::Cash;

        // ExchangeDeferred is a service-internal value — skip the policy resolver.
        if ($effective === RefundDestination::ExchangeDeferred) {
            return RefundDestination::ExchangeDeferred;
        }

        return $this->destinationResolver->resolve(
            $effective,
            $original,
            $policy,
            $cashierPermissions,
        );
    }

    /**
     * Determine a machine-readable policy_trigger string to record on the return receipt.
     *
     * @param  numeric-string  $returnTotal  Negative return total
     */
    private function resolvePolicyTrigger(
        bool $outOfWindow,
        string $returnTotal,
        ReservationSettings $policy,
        ?string $authorizedByUserId,
    ): ?string {
        if ($outOfWindow && $authorizedByUserId !== null) {
            return 'out_of_window_override';
        }

        if ($outOfWindow) {
            return 'out_of_window';
        }

        $absAmount = $this->absTotal($returnTotal);
        $scale = $this->scale();

        /** @var numeric-string $threshold */
        $threshold = $policy->managerOverrideThresholdAmount;

        if (bccomp($absAmount, $threshold, $scale) > 0) {
            return 'over_threshold';
        }

        return null;
    }

    // =========================================================================
    // Draft builder
    // =========================================================================

    /**
     * Build and persist the return-receipt in pending_seal state.
     *
     * Generates the receipt_number and receipt_year from the terminal's current sequence
     * (same approach as ReceiptCreationService).  The terminal sequence is NOT advanced
     * here — that happens inside ReceiptFinalizationService::finalize() when the chain_sequence
     * and previous_hash are also written.
     *
     * The legacy vat_breakdown_hash and payment_methods_hash columns (required NOT NULL)
     * are computed here so the INSERT succeeds.  For return receipts there are no payments,
     * so payment_methods_hash covers an empty set.
     *
     * @param  array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}>  $vatAggregates
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $totalTax
     * @param  numeric-string  $totalDiscount
     * @param  numeric-string  $total
     */
    private function buildReturnDraft(
        Receipt $originalReceipt,
        Terminal $terminal,
        User $cashier,
        ReturnReason $returnReason,
        array $vatAggregates,
        string $subtotal,
        string $totalTax,
        string $totalDiscount,
        string $total,
        string $currency,
        ?string $notes,
        bool $outOfWindow,
        ?string $policyTrigger,
        ?string $authorizedByUserId,
        ?string $overrideReason,
        ?string $refundRequestId,
        ?string $exchangeGroupId = null,
    ): Receipt {
        $now = Carbon::now();
        $receiptId = Str::uuid()->toString();

        // Compute legacy v2 hashes (required NOT NULL columns)
        $vatHash = $this->receiptHashService->hashVATBreakdown(array_values($vatAggregates));
        $paymentHash = $this->receiptHashService->hashPaymentMethods([]);

        // Generate receipt_number from terminal sequence (mirrors ReceiptCreationService)
        $currentYear = (int) $now->format('Y');
        if ($terminal->needsSequenceReset()) {
            $terminal->current_year = $currentYear;
            $terminal->current_sequence = 1;
            $terminal->save();
        }

        $sequence = $terminal->current_sequence;
        $terminalCode = $terminal->code;
        $sequenceStr = str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
        $receiptNumber = "RET-{$terminalCode}-{$currentYear}-{$sequenceStr}";

        /** @var Receipt $draft */
        $draft = new Receipt([
            'tenant_id' => $terminal->tenant_id,
            'company_id' => $originalReceipt->company_id,
            'location_id' => $originalReceipt->location_id,
            'terminal_id' => $terminal->id,
            'receipt_number' => $receiptNumber,
            'receipt_year' => $currentYear,
            'vat_breakdown_hash' => $vatHash,
            'payment_methods_hash' => $paymentHash,
            // chain_sequence and previous_hash are set by ReceiptFinalizationService
            'receipt_type' => ReceiptType::Return,
            'original_receipt_id' => $originalReceipt->id,
            'return_reason' => $returnReason,
            'posted_at' => $now,
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name ?? 'Unknown',
            'subtotal' => $subtotal,
            'tax_amount' => $totalTax,
            'discount_amount' => $totalDiscount,
            'total' => $total,
            'currency' => $currency,
            'consumption_mode' => $originalReceipt->consumption_mode,
            'customer_name' => $originalReceipt->customer_name,
            'customer_identifier' => $originalReceipt->customer_identifier,
            'partner_id' => $originalReceipt->partner_id,
            'is_voided' => false,
            'fiscal_status' => FiscalStatus::PendingSeal,
            'notes' => $notes,
            // Audit fields (Task 27 / Task 31)
            'out_of_window' => $outOfWindow ?: null,
            'policy_trigger' => $policyTrigger,
            'authorized_by_user_id' => $authorizedByUserId,
            'override_reason' => $overrideReason,
            'refund_request_id' => $refundRequestId,
            // Exchange link (Phase F / Task 36)
            'exchange_group_id' => $exchangeGroupId,
        ]);

        $draft->id = $receiptId;
        $draft->setRelation('terminal', $terminal);
        $draft->save();

        return $draft;
    }

    // =========================================================================
    // Destination-specific side effects
    // =========================================================================

    /**
     * Issue a store-voucher for the refund amount BEFORE finalization.
     *
     * The VoucherLedger row's receipt_id is set to the draft receipt's id so the
     * V3ReceiptHashComputer can read it via Receipt::voucherLedgerEntries().
     *
     * @param  numeric-string  $absTotal  Positive refund amount
     */
    private function executeVoucherIssuance(
        Receipt $draft,
        User $cashier,
        Terminal $terminal,
        ?string $authorizedByUserId,
        ?string $overrideReason,
        ?string $policyTrigger,
        string $absTotal,
        string $currency,
        ReservationSettings $policy,
    ): Voucher {
        $expiresAt = $policy->voucherDefaultExpiryDays > 0
            ? Carbon::now()->addDays($policy->voucherDefaultExpiryDays)
            : null;

        $request = new VoucherIssuanceRequest(
            amount: $absTotal,
            currency: $currency,
            tenantId: $terminal->tenant_id,
            companyId: $draft->company_id,
            issuedByUserId: $cashier->id,
            sourceReceiptId: $draft->id,
            issuedToPartnerId: $draft->partner_id,
            issuedAtTerminalId: $terminal->id,
            expiresAt: $expiresAt,
            authorizedByUserId: $authorizedByUserId,
            overrideReason: $overrideReason,
            policyTrigger: $policyTrigger,
        );

        return $this->voucherIssuanceService->issueFromRefund($request);
    }

    /**
     * Prorate the refund across the original receipt's treasury payments.
     *
     * @param  numeric-string  $absTotal  Positive refund amount
     * @return array<RefundAllocation>
     */
    private function executePaymentRefund(
        Receipt $originalReceipt,
        string $absTotal,
        ReservationSettings $policy,
        ?string $refundRequestId,
        ?string $authorizedByUserId,
        ?string $policyTrigger,
    ): array {
        $prorationStrategy = ProrationStrategy::tryFrom($policy->prorationStrategy)
            ?? ProrationStrategy::Proportional;

        // When no idempotency key was supplied by the caller, generate one so that
        // the PaymentRefundService uniqueness index has a valid value.
        $safeRefundRequestId = $refundRequestId ?? (string) Str::uuid();

        return $this->paymentRefundService->refundReceiptPayments(
            originalReceipt: $originalReceipt,
            totalToRefund: $absTotal,
            strategy: $prorationStrategy,
            refundRequestId: $safeRefundRequestId,
            authorizedByUserId: $authorizedByUserId,
            policyTrigger: $policyTrigger,
        );
    }

    /**
     * Record a cash-drawer refund operation.
     *
     * @param  numeric-string  $returnTotal  Negative total
     */
    private function executeCashRefund(
        Receipt $draft,
        string $returnTotal,
        User $cashier,
        Shift $shift,
    ): void {
        // Return total is negative; refund amount is positive
        $refundAmount = bcmul($returnTotal, '-1', $this->scale());

        if (bccomp($refundAmount, '0.00', $this->scale()) <= 0) {
            return;
        }

        $this->cashDrawerService->recordRefund(
            $shift,
            $refundAmount,
            $cashier,
            $draft->id,
        );
    }

    // =========================================================================
    // Totals computation
    // =========================================================================

    /**
     * Compute return totals from validated return lines.
     *
     * @param  array<int, array{original_line: ReceiptLine, quantity: string, disposition: ReturnLineDisposition, physical_receipt: bool|null, resalable: bool|null}>  $validatedLines
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}>,
     *     2: numeric-string,
     *     3: numeric-string,
     *     4: numeric-string,
     *     5: numeric-string,
     * }
     */
    private function computeReturnTotals(
        array $validatedLines,
        string $currency,
        Receipt $originalReceipt,
    ): array {
        $s = $this->scale();

        /** @var list<array<string, mixed>> $receiptLines */
        $receiptLines = [];
        /** @var array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}> $vatAggregates */
        $vatAggregates = [];
        /** @var numeric-string $subtotal */
        $subtotal = '0.00';
        /** @var numeric-string $totalTax */
        $totalTax = '0.00';
        /** @var numeric-string $totalDiscount */
        $totalDiscount = '0.00';

        foreach ($validatedLines as $index => $returnLine) {
            /** @var ReceiptLine $originalLine */
            $originalLine = $returnLine['original_line'];
            /** @var numeric-string $returnQuantity */
            $returnQuantity = $returnLine['quantity'];

            $ratio = bcdiv($returnQuantity, (string) $originalLine->quantity, 10);

            // Negative line total
            /** @var numeric-string $lineTotal */
            $lineTotal = bcmul(
                bcmul($ratio, (string) $originalLine->line_total, $s),
                '-1',
                $s,
            );

            $taxRate = (string) $originalLine->tax_rate;
            $taxRateDecimal = bcdiv($taxRate, '100', 6);
            $divisor = bcadd('1', $taxRateDecimal, 6);
            /** @var numeric-string $netAmount */
            $netAmount = bcdiv($lineTotal, $divisor, $s);
            /** @var numeric-string $taxAmount */
            $taxAmount = bcsub($lineTotal, $netAmount, $s);

            /** @var numeric-string $subtotal */
            $subtotal = bcadd($subtotal, $netAmount, $s);
            /** @var numeric-string $totalTax */
            $totalTax = bcadd($totalTax, $taxAmount, $s);

            /** @var numeric-string $discountAmount */
            $discountAmount = bcmul($ratio, (string) $originalLine->discount_amount, $s);
            /** @var numeric-string $totalDiscount */
            $totalDiscount = bcadd($totalDiscount, $discountAmount, $s);

            $receiptLines[] = [
                'line_number' => $index + 1,
                'original_line_id' => $originalLine->id,
                'product_id' => $originalLine->product_id,
                'variant_id' => $originalLine->variant_id,
                'composite_item_id' => $originalLine->composite_item_id,
                'product_code' => $originalLine->product_code,
                'product_name' => $originalLine->product_name,
                'product_description' => $originalLine->product_description,
                'quantity' => '-'.$returnQuantity,
                'unit' => $originalLine->unit,
                'unit_price' => (string) $originalLine->unit_price,
                'line_total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'modifiers' => $originalLine->modifiers,
                'discount_amount' => $discountAmount,
                'discount_reason' => $originalLine->discount_reason,
                'disposition' => $returnLine['disposition']->value,
                'physical_receipt' => $returnLine['physical_receipt'],
                'resalable' => $returnLine['resalable'],
            ];

            $rateKey = $taxRate;
            if (! isset($vatAggregates[$rateKey])) {
                $vatAggregates[$rateKey] = [
                    'tax_rate' => $taxRate,
                    'net_amount' => '0.00',
                    'vat_amount' => '0.00',
                    'gross_amount' => '0.00',
                ];
            }

            /** @var array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string} $vatEntry */
            $vatEntry = $vatAggregates[$rateKey];
            $vatEntry['net_amount'] = bcadd($vatEntry['net_amount'], $netAmount, $s);
            $vatEntry['vat_amount'] = bcadd($vatEntry['vat_amount'], $taxAmount, $s);
            $vatEntry['gross_amount'] = bcadd($vatEntry['gross_amount'], $lineTotal, $s);
            $vatAggregates[$rateKey] = $vatEntry;
        }

        // ── D-1 gate r1 (treasury) finding F-2 ──────────────────────────────
        // Everything above RECOMPUTES the return's VAT from `line_total` — the
        // PRE-remise line gross. On an original sealed at SALE_RECEIPT
        // `event_version >= 5` that base no longer exists: the sale declared a
        // POST-remise base, so a return computed this way reverses VAT the
        // tenant never collected (`discount x rate/(1+rate)` on a full return
        // of the ruling's worked example: 5.547 TND), and `pos_receipts` return
        // rows feed the declaration verbatim as `-ABS(...)`.
        //
        // A return of a v5 original therefore REVERSES THE SEALED ROWS
        // pro-rata instead — the same posture the rest of D-1 takes: the device
        // is the fiscal authority and the server only ever adds up and scales
        // what it sealed. A FULL return has share == 1 and reverses the sealed
        // breakdown exactly; a partial return takes each rate group's share of
        // its own PRE-remise gross, which is the only ratio the original's
        // lines can express.
        //
        // Pre-D-1 originals keep the recomputation verbatim: their sealed base
        // IS the line roll-up, so the two agree, and changing it would move
        // numbers on receipts that are already correct.
        $sealedReversal = $this->postRemiseSealedReversal($originalReceipt, $vatAggregates, $s);
        if ($sealedReversal !== null) {
            $vatAggregates = $sealedReversal;
            /** @var numeric-string $subtotal */
            $subtotal = '0.00';
            /** @var numeric-string $totalTax */
            $totalTax = '0.00';
            foreach ($vatAggregates as $vatData) {
                /** @var numeric-string $subtotal */
                $subtotal = bcadd($subtotal, $vatData['net_amount'], $s);
                /** @var numeric-string $totalTax */
                $totalTax = bcadd($totalTax, $vatData['vat_amount'], $s);
            }
        } else {
            // Recalculate VAT from aggregate net_amount
            /** @var numeric-string $totalTax */
            $totalTax = '0.00';
            foreach ($vatAggregates as $rk => $vatData) {
                /** @var array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string} $vatData */
                $recalcVat = $this->roundVat($vatData['net_amount'], $vatData['tax_rate']);
                /** @var numeric-string $recalcGross */
                $recalcGross = bcadd($vatData['net_amount'], $recalcVat, $s);
                $vatAggregates[$rk] = array_merge($vatData, [
                    'vat_amount' => $recalcVat,
                    'gross_amount' => $recalcGross,
                ]);
                /** @var numeric-string $totalTax */
                $totalTax = bcadd($totalTax, $recalcVat, $s);
            }
        }

        // The refund total is the discounted net plus tax (the line totals
        // already reflect line-level discounts).
        /** @var numeric-string $total */
        $total = bcadd($subtotal, $totalTax, $s);

        // Persisted header columns must satisfy the pos_receipts_totals invariant
        // total = subtotal + tax_amount - discount_amount (enforced by PostgreSQL).
        // discount_amount carries the proportional line-discount sum for audit, so
        // the stored subtotal is the PRE-discount net; the refund `total` is
        // unchanged because the discount cancels out:
        //   (net + discount) + tax - discount = net + tax = total.
        //
        // D-1: on the sealed-reversal arm the stored `subtotal` is the reversed
        // POST-remise base, so `total = subtotal + tax_amount` — the second arm
        // of the widened `pos_receipts_totals` CHECK — and the line-discount
        // sum must NOT be added back (it is already inside the sealed base).
        // `discount_amount` still carries it for audit.
        /** @var numeric-string $headerSubtotal */
        $headerSubtotal = $sealedReversal !== null
            ? $subtotal
            : bcadd($subtotal, $totalDiscount, $s);
        /** @var numeric-string $headerDiscount */
        $headerDiscount = $sealedReversal !== null ? bcadd('0', '0', $s) : $totalDiscount;

        return [$receiptLines, $vatAggregates, $headerSubtotal, $totalTax, $headerDiscount, $total];
    }

    /**
     * D-1 (owner ruling 2026-08-25, gate r1 treasury F-2) — reverse a return's
     * VAT from the ORIGINAL's SEALED per-rate breakdown, pro-rata.
     *
     * Returns `null` when the original was sealed BEFORE the cutover — its
     * sealed base is the line roll-up, the caller's recomputation reproduces it,
     * and those receipts must keep booking exactly as they always have.
     *
     * ## The ratio
     *
     * For each rate group, `share_r = refunded PRE-remise gross / the original's
     * OWN PRE-remise gross at that rate`. The original's lines are the only
     * thing that can express "how much of this group is coming back", and their
     * gross is pre-remise on both sides of the fraction, so the remise cancels
     * out of the ratio entirely. A FULL return has `share_r == 1` and reverses
     * the sealed rows to the millime.
     *
     * `net_r` and `vat_r` are then scaled INDEPENDENTLY off the sealed figures
     * and `gross_r` is their sum, so the reversed rows can never imply a rate
     * the sale did not seal.
     *
     * The discriminator is the same one the ledger arm uses:
     * `pos_receipt_vat_details.discount_allocated`, NULL on every receipt sealed
     * at `event_version <= 4`. A MIXED set is refused rather than guessed —
     * reversing half a receipt under each era would put the declaration out by
     * an amount nobody could later reconstruct.
     *
     * @param  array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}>  $refunded  negative-signed, keyed by rate
     * @return array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}>|null
     *
     * @throws \RuntimeException when the original's sealed rows are unusable
     */
    private function postRemiseSealedReversal(Receipt $originalReceipt, array $refunded, int $s): ?array
    {
        $sealed = DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $originalReceipt->id)
            ->get(['tax_rate', 'net_amount', 'vat_amount', 'discount_allocated']);

        if ($sealed->isEmpty()) {
            return null;
        }

        $withShare = 0;
        $withoutShare = 0;
        foreach ($sealed as $row) {
            if ($row->discount_allocated === null) {
                $withoutShare++;

                continue;
            }
            $withShare++;
        }
        if ($withShare > 0 && $withoutShare > 0) {
            throw new \RuntimeException(sprintf(
                'pos_return_sealed_base_era_ambiguous:receipt=%s:rows_with_discount_allocated=%d:rows_without=%d '
                .'— the original\'s sealed VAT rows disagree about whether the taxable base is net of the '
                .'transaction remise (D-1) or gross of it, so its return cannot be reversed without guessing.',
                (string) $originalReceipt->id,
                $withShare,
                $withoutShare,
            ));
        }
        if ($withShare === 0) {
            return null;
        }

        // The original's OWN pre-remise gross per rate — the denominator.
        /** @var array<string, numeric-string> $originalGross */
        $originalGross = [];
        foreach ($originalReceipt->lines as $line) {
            $rate = $this->normalisedRateKey((string) $line->tax_rate);
            /** @var numeric-string $lineTotal */
            $lineTotal = (string) $line->line_total;
            $originalGross[$rate] = bcadd($originalGross[$rate] ?? '0', $lineTotal, $s);
        }

        /** @var array<string, array{net: numeric-string, vat: numeric-string}> $sealedByRate */
        $sealedByRate = [];
        foreach ($sealed as $row) {
            $rate = $this->normalisedRateKey((string) $row->tax_rate);
            /** @var numeric-string $net */
            $net = (string) $row->net_amount;
            /** @var numeric-string $vat */
            $vat = (string) $row->vat_amount;
            $sealedByRate[$rate] = [
                'net' => bcadd($sealedByRate[$rate]['net'] ?? '0', $net, $s),
                'vat' => bcadd($sealedByRate[$rate]['vat'] ?? '0', $vat, $s),
            ];
        }

        $out = [];
        foreach ($refunded as $key => $group) {
            $rate = $this->normalisedRateKey($group['tax_rate']);
            $denominator = $originalGross[$rate] ?? '0';
            $sealedRow = $sealedByRate[$rate] ?? null;
            if ($sealedRow === null || bccomp($denominator, '0', $s) === 0) {
                throw new \RuntimeException(sprintf(
                    'pos_return_sealed_rate_missing:receipt=%s:rate=%s — the returned lines carry a VAT rate the '
                    .'original receipt did not seal, so the reversal has no sealed figure to scale.',
                    (string) $originalReceipt->id,
                    $rate,
                ));
            }

            // `$group['gross_amount']` is the refunded PRE-remise gross, already
            // negative-signed; the magnitude is the numerator.
            /** @var numeric-string $refundedGross */
            $refundedGross = bcmul($group['gross_amount'], '-1', $s);
            $ratioScale = $s + self::REVERSAL_RATIO_EXTRA_SCALE;
            $share = bcdiv($refundedGross, $denominator, $ratioScale);

            $net = bcmul(
                TransactionRemiseSplit::roundHalfUp(bcmul($sealedRow['net'], $share, $ratioScale), $s),
                '-1',
                $s,
            );
            $vat = bcmul(
                TransactionRemiseSplit::roundHalfUp(bcmul($sealedRow['vat'], $share, $ratioScale), $s),
                '-1',
                $s,
            );

            $out[$key] = [
                'tax_rate' => $group['tax_rate'],
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => bcadd($net, $vat, $s),
            ];
        }

        return $out;
    }

    /**
     * `pos_receipt_vat_details.tax_rate` is `decimal(5,2)` but the driver
     * formats it differently (PG `'19.00'`, SQLite `'19'`), and the return
     * aggregates key on `pos_receipt_lines.tax_rate`. Both are normalised to 2
     * dp so the two sides join.
     */
    private function normalisedRateKey(string $rate): string
    {
        // precision-ok: a VAT RATE is a percentage, not money — `tax_rate` is
        // decimal(5,2) and rule 19 keeps percents off the currency scale.
        return bcadd(is_numeric($rate) ? $rate : '0', '0', 2);
    }

    // =========================================================================
    // Validation helpers
    // =========================================================================

    /**
     * Validate that the original receipt can be returned.
     *
     * @throws \RuntimeException If receipt cannot be returned
     */
    private function validateOriginalReceipt(Receipt $receipt): void
    {
        if ($receipt->is_voided) {
            throw new \RuntimeException('Cannot return a voided receipt');
        }

        if ($receipt->receipt_type === ReceiptType::Return) {
            throw new \RuntimeException('Cannot return a return receipt');
        }
    }

    /**
     * Validate return quantities against original receipt, accounting for previous returns.
     *
     * @param  Receipt  $originalReceipt  The original receipt with lines and returnReceipts loaded
     * @param  array<int, array{line_id: string, quantity: string, disposition?: string|null, physical_receipt?: bool|null, resalable?: bool|null}>  $returnLines
     * @return array<int, array{original_line: ReceiptLine, quantity: string, already_returned: string, disposition: ReturnLineDisposition, physical_receipt: bool|null, resalable: bool|null}>
     *
     * @throws \InvalidArgumentException If quantities are invalid or disposition combos are illegal
     */
    private function validateReturnQuantities(Receipt $originalReceipt, array $returnLines): array
    {
        $alreadyReturned = $this->calculateAlreadyReturnedQuantities($originalReceipt);

        $originalLinesById = $originalReceipt->lines->keyBy('id');
        $validated = [];

        foreach ($returnLines as $returnLine) {
            $lineId = $returnLine['line_id'];
            /** @var numeric-string $requestedQuantity */
            $requestedQuantity = (string) $returnLine['quantity'];

            if (! $originalLinesById->has($lineId)) {
                throw new \InvalidArgumentException("Line '{$lineId}' not found on original receipt");
            }

            /** @var ReceiptLine $originalLine */
            $originalLine = $originalLinesById->get($lineId);

            if (bccomp($requestedQuantity, '0', 4) <= 0) {
                throw new \InvalidArgumentException("Return quantity must be positive for line '{$lineId}'");
            }

            /** @var numeric-string $alreadyReturnedQty */
            $alreadyReturnedQty = $alreadyReturned[$lineId] ?? '0.0000';
            /** @var numeric-string $remainingReturnable */
            $remainingReturnable = bcsub((string) $originalLine->quantity, $alreadyReturnedQty, 4); // precision-ok: 4 = canonical quantity storage scale

            if (bccomp($requestedQuantity, $remainingReturnable, 4) > 0) { // precision-ok: 4 = canonical quantity storage scale
                throw new \InvalidArgumentException(
                    "Cannot return {$requestedQuantity} of '{$originalLine->product_name}'. "
                    ."Maximum returnable: {$remainingReturnable} (original: {$originalLine->quantity}, already returned: {$alreadyReturnedQty})"
                );
            }

            // ─────────────────────────────────────────────────────────────────
            // Parse per-line disposition (default RESTOCK when absent)
            // ─────────────────────────────────────────────────────────────────
            $dispositionRaw = $returnLine['disposition'] ?? null;
            $disposition = $dispositionRaw === null
                ? ReturnLineDisposition::Restock
                : (ReturnLineDisposition::tryFrom((string) $dispositionRaw)
                    ?? throw new \InvalidArgumentException("Invalid disposition '{$dispositionRaw}' for line '{$lineId}'"));

            $physicalReceipt = array_key_exists('physical_receipt', $returnLine) ? (bool) $returnLine['physical_receipt'] : null;
            $resalable = array_key_exists('resalable', $returnLine) ? ($returnLine['resalable'] === null ? null : (bool) $returnLine['resalable']) : null;

            // ─────────────────────────────────────────────────────────────────
            // Service-side fail-closed illegal-combo guard (defense in depth
            // vs the request layer — catches direct service callers too).
            // ─────────────────────────────────────────────────────────────────
            if ($disposition === ReturnLineDisposition::Restock && ($physicalReceipt === false || $resalable === false)) {
                throw new \InvalidArgumentException("RESTOCK requires the item received and resalable for line '{$lineId}'");
            }

            if ($disposition === ReturnLineDisposition::NotReceived && $physicalReceipt === true) {
                throw new \InvalidArgumentException("NOT_RECEIVED cannot have physical_receipt=true for line '{$lineId}'");
            }

            // ─────────────────────────────────────────────────────────────────
            // Regulated-goods guard: products with restock_policy = never may
            // never be restocked. SCRAP and NOT_RECEIVED are unaffected.
            // ─────────────────────────────────────────────────────────────────
            if ($disposition === ReturnLineDisposition::Restock && $originalLine->product_id !== null) {
                $effective = $this->restockPolicyResolver->resolve($originalLine->product_id);
                if ($effective->policy === RestockPolicy::Never) {
                    throw new \InvalidArgumentException(
                        "Restock not permitted for regulated product on line '{$lineId}' (policy: never)"
                    );
                }
            }

            $validated[] = [
                'original_line' => $originalLine,
                'quantity' => $requestedQuantity,
                'already_returned' => $alreadyReturnedQty,
                'disposition' => $disposition,
                'physical_receipt' => $physicalReceipt,
                'resalable' => $resalable,
            ];
        }

        return $validated;
    }

    /**
     * MAGNITUDE of a stored return-line quantity, at the canonical quantity
     * storage scale.
     *
     * **Normalises the two refund SIGN ERAS onto one convention** — the same
     * `-ABS(...)` normalisation wave 4 applied to its six aggregate consumers
     * (`PosAnalyticsService`, `ReportGenerationService`, `GrandtotalService`),
     * which this PHP loop was missed by because it is not a SQL aggregate:
     *
     *  - LEGACY returns store a NEGATIVE `pos_receipt_lines.quantity`;
     *  - v4 refunds store a POSITIVE MAGNITUDE — `PosCoreReceiptProjection`
     *    writes the canonical `line_items[].quantity` verbatim, and the fiscal
     *    payload validator's quantity regex forbids a leading `-`.
     *
     * Flipping the sign (the previous `bcmul($q, '-1')`) is only correct in
     * the legacy era. On a v4 refund line it produced a NEGATIVE
     * already-returned tally, which `bcsub`-ed out of the original quantity
     * GREW `remainingReturnable` — handing out double the original quantity
     * and allowing the same line to be refunded twice (whole-branch review
     * C-5/N-1: double payout + double restock). Reachable as soon as
     * `pos:disable-v4-refund-authoring` routes a v4-refunded terminal back to
     * this legacy path.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function quantityMagnitude(string $value): string
    {
        return bccomp($value, '0', 4) < 0 // precision-ok: 4 = canonical quantity storage scale
            ? bcsub('0', $value, 4) // precision-ok: 4 = canonical quantity storage scale
            : bcadd($value, '0', 4); // precision-ok: 4 = canonical quantity storage scale
    }

    /**
     * Calculate already-returned quantities per original line.
     *
     * @return array<string, string> Map of original line ID to returned quantity
     */
    private function calculateAlreadyReturnedQuantities(Receipt $originalReceipt): array
    {
        $returned = [];

        foreach ($originalReceipt->returnReceipts as $returnReceipt) {
            if ($returnReceipt->is_voided) {
                continue;
            }

            foreach ($returnReceipt->lines as $returnLine) {
                // Canonical quantity scale is 4 END-TO-END here (Codex r1 B1):
                // truncating at 3 let a 4-decimal request (e.g. 1.0009 against
                // an original 1.0000) slip past the remaining-returnable cap.
                // MAGNITUDE, never a sign flip — see quantityMagnitude().
                $absQuantity = $this->quantityMagnitude((string) $returnLine->quantity);

                if ($returnLine->original_line_id !== null) {
                    $key = $returnLine->original_line_id;
                    $returned[$key] = bcadd($returned[$key] ?? '0.0000', $absQuantity, 4); // precision-ok: 4 = canonical quantity storage scale

                    continue;
                }

                // Legacy fallback: match by product attributes
                foreach ($originalReceipt->lines as $originalLine) {
                    $sameProduct = (
                        $originalLine->product_id === $returnLine->product_id
                        && $originalLine->composite_item_id === $returnLine->composite_item_id
                        && $originalLine->product_code === $returnLine->product_code
                    );

                    if ($sameProduct) {
                        $key = $originalLine->id;
                        $returned[$key] = bcadd($returned[$key] ?? '0.0000', $absQuantity, 4); // precision-ok: 4 = canonical quantity storage scale
                        break;
                    }
                }
            }
        }

        return $returned;
    }

    // =========================================================================
    // Stock restoration
    // =========================================================================

    /**
     * Restore stock for a returned product.
     *
     * Variant-scoped (F1, variant retrofit audit 2026-06-10): the restore
     * targets the exact stock_levels row the sale decremented. When the
     * original line carries a variant_id (draft path) the variant row is
     * restored; when it is NULL (projection path) the variant_id IS NULL
     * row is restored — symmetric with ReceiptCreationService::decrementStock
     * and PosCoreReceiptProjection::decrementStock respectively.
     *
     * @param  numeric-string  $quantity
     */
    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string|null  $unitCost
     */
    private function restoreStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $returnReceiptId,
        string $cashierId,
        string $currencyCode,
        ?CarbonInterface $entryDate,
        ?string $unitCost = null,
        bool $isHistorical = false,
        ?string $variantId = null,
    ): ?StockMovement {
        $stockLevel = $this->resolveStockLevelForUpdate($productId, $locationId, $companyId, $variantId);

        if ($stockLevel === null) {
            Log::warning('No stock level found for product during return stock restore', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ]);

            return null;
        }

        $quantityBefore = (string) $stockLevel->quantity;
        // stock_levels.quantity and pos_receipt_lines.quantity are stored at
        // scale 4 (canonical quantity storage scale). Add at scale 4 so
        // sub-centi returned quantities are not truncated to zero.
        $quantityAfter = bcadd((string) $stockLevel->quantity, $quantity, 4); // 4 = canonical quantity storage scale

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        $resolvedUnitCost = $this->resolveReturnUnitCost($tenantId, $companyId, $productId, $unitCost);

        $movement = StockMovement::create([
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
            'unit_cost' => CurrencyScale::bcformat($resolvedUnitCost, 6),
            'total_cost' => CurrencyScale::bcformat(
                bcmul($quantity, $resolvedUnitCost, 6), // precision-ok: inventory cost product at COST_SCALE
                6,
            ),
            'reference' => 'POS Return',
            'reference_type' => 'pos_receipt_return',
            'reference_id' => $returnReceiptId,
            'notes' => "Stock returned via POS return (return receipt: {$returnReceiptId})",
            'user_id' => $cashierId,
            'is_historical' => $isHistorical,
            // Server-side return processing time — no device event time exists
            // in this flow (the device authors refunds via the projection path).
            'occurred_at' => now(),
        ]);

        $occurredAt = $movement->occurred_at ?? $movement->created_at ?? now();
        $this->glBuffer->enqueue(new MovementGlContext(
            kind: MovementGlKind::Entry,
            movementId: $movement->id,
            companyId: $movement->company_id,
            currencyCode: $currencyCode,
            reason: $movement->reason ?? throw new \LogicException('POS return movement is missing its GL reason.'),
            quantityBefore: (string) $movement->quantity_before,
            quantityAfter: (string) $movement->quantity_after,
            unitCost: (string) ($movement->unit_cost ?? '0'),
            sourceType: $movement->reference_type,
            sourceId: $movement->reference_id,
            occurredAt: \DateTimeImmutable::createFromInterface($occurredAt),
            entryDate: \DateTimeImmutable::createFromInterface($entryDate ?? now()),
            postedByUserId: $cashierId,
            isHistorical: (bool) $movement->is_historical,
        ));

        return $movement;
    }

    /**
     * Resolve the StockLevel row for the given product/location/company/variant,
     * acquiring a row-level lock for the enclosing transaction.
     *
     * Shared by restoreStock (receipt leg) and writeOffReturnedStock (write-off leg)
     * so that the four-clause variant-aware WHERE is maintained in exactly one place.
     */
    private function resolveStockLevelForUpdate(
        string $productId,
        string $locationId,
        string $companyId,
        ?string $variantId,
    ): ?StockLevel {
        return StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->when(
                $variantId !== null,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->whereNull('variant_id'),
            )
            ->lockForUpdate()
            ->first();
    }

    /**
     * The SCRAP disposition pair — receive the goods back, then DESTROY them —
     * applied ATOMICALLY and CONTAINED (DPA V10 + gate fix round 1).
     *
     * COST-BEARING. The destruction leg used to be a raw quantity-only
     * `StockMovement::create()` with explicitly no unit cost, no WAC involvement
     * and no GL — the return note was silently doing double duty as a destruction
     * document and inventory value walked off the balance sheet. It now goes
     * through the settled write-off idiom (`ReturnScrapWriteOffService`):
     * cost-resolved `StockAdjustmentService::issue()` + a movement-keyed
     * Dr Shrinkage / Cr Inventory entry sealed in this same transaction.
     *
     * ATOMIC (gate C1). The two legs are only meaningful together, so they run
     * inside ONE SAVEPOINT. If the destruction leg cannot be recorded — archived
     * product, variant-grain mismatch, an over-reserved row that fails `issue()`'s
     * availability check — the re-entry leg is rolled back with it. Committing the
     * re-entry leg alone would permanently ADD physically destroyed goods to
     * sellable stock, which is worse than recording nothing.
     *
     * CONTAINED (gate I3). A destruction leg appended to an ALREADY AUTHORISED
     * refund must never turn that refund into a 500: `issue()`'s
     * `quantity − reserved` availability rule is semantically irrelevant when
     * destroying goods you physically hold, and a legacy line's NULL variant_id on
     * a since-variantised product is a data-shape artefact, not a reason to refuse
     * a customer's money. Both are logged at ERROR and skipped. This also makes
     * the interactive path behave EXACTLY like the projection path for the same
     * economic act.
     *
     * NOT contained: retryable concurrency faults — see ConcurrencyFault.
     *
     * @param  numeric-string  $quantity
     */
    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string|null  $unitCost
     */
    private function applyScrapPair(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $returnReceiptId,
        string $returnReceiptNumber,
        string $currencyCode,
        string $cashierId,
        ?string $variantId = null,
        ?CarbonInterface $entryDate = null,
        ?string $unitCost = null,
        bool $isHistorical = false,
    ): void {
        // No stock_levels row at all (service / non-inventory item): both legs are
        // no-ops by construction — `restoreStock` logs and skips, and there is
        // nothing to destroy. Return BEFORE the savepoint so this benign case does
        // not surface as an ERROR-level containment (gate M2 log-noise).
        if ($this->resolveStockLevelForUpdate($productId, $locationId, $companyId, $variantId) === null) {
            Log::warning('No stock level for scrap pair during return; neither leg recorded', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'return_receipt_id' => $returnReceiptId,
            ]);

            return;
        }

        $marker = $this->glBuffer->mark();

        try {
            DB::transaction(function () use (
                $tenantId, $companyId, $locationId, $productId, $quantity,
                $returnReceiptId, $returnReceiptNumber, $currencyCode, $cashierId, $variantId, $entryDate,
                $unitCost, $isHistorical,
            ): void {
                $this->restoreStock(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    locationId: $locationId,
                    productId: $productId,
                    quantity: $quantity,
                    returnReceiptId: $returnReceiptId,
                    cashierId: $cashierId,
                    variantId: $variantId,
                    currencyCode: $currencyCode,
                    entryDate: $entryDate,
                    unitCost: $unitCost,
                    isHistorical: $isHistorical,
                );

                $this->returnScrapWriteOffService->writeOff(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    locationId: $locationId,
                    productId: $productId,
                    quantity: $quantity,
                    returnReceiptId: $returnReceiptId,
                    returnReceiptNumber: $returnReceiptNumber,
                    currencyCode: $currencyCode,
                    cashierId: $cashierId,
                    variantId: $variantId,
                    entryDate: $entryDate,
                    unitCost: $unitCost,
                    isHistorical: $isHistorical,
                );
            });
        } catch (\Throwable $e) {
            $this->glBuffer->rollbackTo($marker);

            // A deadlock / serialization failure / aborted transaction is a
            // RETRYABLE INFRASTRUCTURE fault, not a domain outcome. Laravel does
            // not issue ROLLBACK TO SAVEPOINT for a nested concurrency error, so
            // swallowing it here would leave the enclosing PostgreSQL transaction
            // aborted and let `runReturnTransaction` "COMMIT" a silently
            // rolled-back refund. Re-throw and let the caller fail loudly.
            if (ConcurrencyFault::isRetryable($e)) {
                throw $e;
            }

            Log::error('POS scrap pair could not be recorded; BOTH legs rolled back, the refund itself is unaffected', [
                'return_receipt_id' => $returnReceiptId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return numeric-string|null */
    private function receiptLineUnitCost(ReceiptLine $line): ?string
    {
        if ($line->unit_cost === null) {
            return null;
        }

        return (string) $line->unit_cost;
    }

    /** @return array{found: bool, unit_cost: numeric-string|null} */
    private function originalSaleMovementUnitCost(
        string $tenantId,
        string $companyId,
        string $originalReceiptId,
        string $productId,
        ?string $variantId,
    ): array {
        $query = StockMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('reference_type', 'pos_receipt')
            ->where('reference_id', $originalReceiptId)
            ->where('product_id', $productId)
            ->where('reason', MovementReason::POSSale);

        $variantId === null
            ? $query->whereNull('variant_id')
            : $query->where('variant_id', $variantId);

        $movement = $query
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();
        if ($movement === null) {
            return ['found' => false, 'unit_cost' => null];
        }

        return [
            'found' => true,
            'unit_cost' => $movement->unit_cost === null ? null : (string) $movement->unit_cost,
        ];
    }

    /**
     * @param  numeric-string|null  $unitCost
     * @return numeric-string
     */
    private function resolveReturnUnitCost(
        string $tenantId,
        string $companyId,
        string $productId,
        ?string $unitCost,
    ): string {
        if ($unitCost !== null) {
            return $unitCost;
        }

        $product = Product::query()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($productId);

        $resolved = $product->resolveMovementUnitCost();
        if (! is_numeric($resolved)) {
            throw new \LogicException('Resolved POS return unit cost must be numeric.');
        }

        return $resolved;
    }

    private function originalReceiptPredatesInventoryGlCutover(string $receiptId): bool
    {
        $receipt = Receipt::query()
            ->selectRaw('CASE WHEN pos_receipts.created_at < companies.inventory_gl_cutover_at THEN 1 ELSE 0 END AS gl_is_historical')
            ->join('companies', 'companies.id', '=', 'pos_receipts.company_id')
            ->where('pos_receipts.id', $receiptId)
            ->firstOrFail();

        return (int) $receipt->getAttribute('gl_is_historical') === 1;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return the absolute (positive) value of a possibly-negative amount string.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private function absTotal(string $amount): string
    {
        if (bccomp($amount, '0', $this->scale()) < 0) {
            /** @var numeric-string */
            return bcmul($amount, '-1', $this->scale());
        }

        /** @var numeric-string */
        return $amount;
    }

    /**
     * The lots THIS receipt line consumed, minus what earlier returns of the same
     * line already credited back — the per-line provenance hint for
     * {@see FEFOInventoryService::restoreBatchesForReturn()} (gate r5 R5-1).
     *
     * `pos_receipt_line_batch_allocations` is written on the sale
     * ({@see ReceiptCreationService::allocateBatches()})
     * and names the batch and quantity for every lot the line drew from. It is the
     * only record that says which RECEIPT shipped which lot; the movement ledger
     * aggregates over the whole tuple and cannot.
     *
     * Partial returns are netted the same way the deleted snapshot restore did:
     * each lot's share is scaled by how much of the line has been returned so far,
     * so returning 2 of 5 twice credits the lot twice at its proportional share and
     * never more than it originally took. The service caps every hint by the lot's
     * outstanding outbound anyway, so this arithmetic can only ever under-credit,
     * never over-credit.
     *
     * @param  numeric-string  $alreadyReturnedQuantity
     * @return array<int, numeric-string>
     */
    private function lotProvenanceForLine(ReceiptLine $originalLine, string $alreadyReturnedQuantity): array
    {
        $allocations = ReceiptLineBatchAllocation::where('receipt_line_id', $originalLine->id)->get();

        if ($allocations->isEmpty()) {
            return [];
        }

        /** @var numeric-string $originalQty */
        $originalQty = (string) $originalLine->quantity;

        if (bccomp($originalQty, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity storage scale
            return [];
        }

        $provenance = [];

        foreach ($allocations as $allocation) {
            /** @var numeric-string $allocQty */
            $allocQty = (string) $allocation->quantity;

            // What this lot still owes back: its full share minus the share the
            // prior returns of this line already consumed.
            $scaledShare = bcmul($allocQty, $alreadyReturnedQuantity, 8); // precision-ok: 8 = intermediate, truncated to 4 on the next line
            $alreadyCredited = bcdiv($scaledShare, $originalQty, 4); // precision-ok: 4 = canonical quantity storage scale

            /** @var numeric-string $outstanding */
            $outstanding = bcsub($allocQty, $alreadyCredited, 4); // precision-ok: 4 = canonical quantity storage scale

            if (bccomp($outstanding, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity storage scale
                continue;
            }

            $provenance[(int) $allocation->batch_id] = $outstanding;
        }

        return $provenance;
    }
}
