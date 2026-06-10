<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Concerns\RoundsVat;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\RefundDestination;
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
use App\Modules\Treasury\Application\DTOs\RefundAllocation;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Voucher;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
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

            [$receiptLines, $vatAggregates, $subtotal, $totalTax, $totalDiscount, $total] =
                $this->computeReturnTotals($validatedLines, $currency);

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
            // Step 11: Restore stock
            // ─────────────────────────────────────────────────────────────────
            foreach ($validatedLines as $returnLine) {
                /** @var ReceiptLine $originalLine */
                $originalLine = $returnLine['original_line'];

                if ($originalLine->product_id === null) {
                    continue;
                }

                /** @var numeric-string $qty */
                $qty = $returnLine['quantity'];
                /** @var numeric-string $alreadyReturnedQty */
                $alreadyReturnedQty = $returnLine['already_returned'];

                // Variant symmetry rule: the restore must target the exact
                // stock row the sale decremented. Draft-path lines carry
                // variant_id (variant-scoped decrement); projection-path
                // lines carry NULL (variant_id IS NULL decrement). Never
                // re-derive the variant for NULL lines.
                $this->restoreStock(
                    tenantId: $terminal->tenant_id,
                    companyId: $companyId,
                    locationId: $originalReceipt->location_id,
                    productId: $originalLine->product_id,
                    quantity: $qty,
                    returnReceiptId: $draft->id,
                    cashierId: $cashier->id,
                    variantId: $originalLine->variant_id,
                );

                $this->restoreBatchAllocations(
                    originalLine: $originalLine,
                    returnQuantity: $qty,
                    alreadyReturnedQuantity: $alreadyReturnedQty,
                    locationId: $originalReceipt->location_id,
                );
            }

            // ─────────────────────────────────────────────────────────────────
            // Step 12: Seal via ReceiptFinalizationService (chain advance happens here)
            // ─────────────────────────────────────────────────────────────────
            $sealed = $this->finalizationService->finalize($draft);

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
     * @param  array<int, array{original_line: ReceiptLine, quantity: string}>  $validatedLines
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: array<array-key, array{tax_rate: string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string}>,
     *     2: numeric-string,
     *     3: numeric-string,
     *     4: numeric-string,
     *     5: numeric-string,
     * }
     */
    private function computeReturnTotals(array $validatedLines, string $currency): array
    {
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
        /** @var numeric-string $headerSubtotal */
        $headerSubtotal = bcadd($subtotal, $totalDiscount, $s);

        return [$receiptLines, $vatAggregates, $headerSubtotal, $totalTax, $totalDiscount, $total];
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
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines
     * @return array<int, array{original_line: ReceiptLine, quantity: string, already_returned: string}>
     *
     * @throws \InvalidArgumentException If quantities are invalid
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

            $validated[] = [
                'original_line' => $originalLine,
                'quantity' => $requestedQuantity,
                'already_returned' => $alreadyReturnedQty,
            ];
        }

        return $validated;
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
                $absQuantity = bcmul((string) $returnLine->quantity, '-1', 4); // precision-ok: 4 = canonical quantity storage scale

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
    private function restoreStock(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $returnReceiptId,
        string $cashierId,
        ?string $variantId = null,
    ): void {
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->when(
                $variantId !== null,
                fn ($query) => $query->where('variant_id', $variantId),
                fn ($query) => $query->whereNull('variant_id'),
            )
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            Log::warning('No stock level found for product during return stock restore', [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
            ]);

            return;
        }

        $quantityBefore = (string) $stockLevel->quantity;
        // stock_levels.quantity and pos_receipt_lines.quantity are stored at
        // scale 4 (canonical quantity storage scale). Add at scale 4 so
        // sub-centi returned quantities are not truncated to zero.
        $quantityAfter = bcadd((string) $stockLevel->quantity, $quantity, 4); // 4 = canonical quantity storage scale

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        StockMovement::create([
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
            'reference' => 'POS Return',
            'reference_type' => 'pos_receipt_return',
            'reference_id' => $returnReceiptId,
            'notes' => "Stock returned via POS return (return receipt: {$returnReceiptId})",
            'user_id' => $cashierId,
            'is_historical' => false,
        ]);
    }

    /**
     * Restore batch-level stock (inventory_batch_stock) for a returned line (F4).
     *
     * The sale path consumes batch stock via FEFO and snapshots each consumed
     * batch into a ReceiptLineBatchAllocation row keyed by receipt_line_id.
     * Restitution is proportional to the returned fraction of the line —
     * FEFO order is irrelevant when putting stock back.
     *
     * Cumulative-restitution invariant: after a cumulative total of R units
     * (out of an original line quantity Q) has been returned, each allocation
     * `a` must have been restored by exactly
     *
     *     cumulative(R) = min(a, trunc4(a × R ÷ Q))
     *
     * where trunc4 is bcmath truncation at scale 4 (canonical quantity scale).
     * Each return restores the DELTA cumulative(R_new) − cumulative(R_prev),
     * derived from the persisted already-returned quantities rather than
     * trusting per-return proportional math — so repeated partial returns can
     * never restore more than the original allocation, and a full return
     * restores each allocation exactly (trunc4(a × Q ÷ Q) = a).
     *
     * @param  numeric-string  $returnQuantity  Quantity returned in THIS return
     * @param  numeric-string  $alreadyReturnedQuantity  Quantity returned by prior (non-voided) returns
     */
    private function restoreBatchAllocations(
        ReceiptLine $originalLine,
        string $returnQuantity,
        string $alreadyReturnedQuantity,
        string $locationId,
    ): void {
        $allocations = ReceiptLineBatchAllocation::where('receipt_line_id', $originalLine->id)->get();

        if ($allocations->isEmpty()) {
            return;
        }

        /** @var numeric-string $originalQty */
        $originalQty = (string) $originalLine->quantity;

        if (bccomp($originalQty, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity storage scale
            return;
        }

        /** @var numeric-string $newReturned */
        $newReturned = bcadd($alreadyReturnedQuantity, $returnQuantity, 4); // precision-ok: 4 = canonical quantity storage scale

        foreach ($allocations as $allocation) {
            /** @var numeric-string $allocQty */
            $allocQty = (string) $allocation->quantity;

            $previousCumulative = $this->cumulativeBatchRestitution($allocQty, $alreadyReturnedQuantity, $originalQty);
            $newCumulative = $this->cumulativeBatchRestitution($allocQty, $newReturned, $originalQty);

            /** @var numeric-string $delta */
            $delta = bcsub($newCumulative, $previousCumulative, 4); // precision-ok: 4 = canonical quantity storage scale

            if (bccomp($delta, '0', 4) <= 0) { // precision-ok: 4 = canonical quantity storage scale
                continue;
            }

            /** @var object{id: int|string, quantity: int|float|string}|null $batchStock */
            $batchStock = DB::table('inventory_batch_stock')
                ->where('batch_id', $allocation->batch_id)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($batchStock === null) {
                Log::warning('No batch stock row found during return batch restitution', [
                    'batch_id' => $allocation->batch_id,
                    'receipt_line_id' => $originalLine->id,
                    'location_id' => $locationId,
                ]);

                continue;
            }

            // SQLite returns numeric columns as int/float; normalize to a
            // numeric-string before bcmath.
            /** @var numeric-string $batchQuantity */
            $batchQuantity = (string) $batchStock->quantity;

            DB::table('inventory_batch_stock')
                ->where('id', $batchStock->id)
                ->update([
                    'quantity' => bcadd($batchQuantity, $delta, 4), // precision-ok: 4 = canonical quantity storage scale
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Cumulative batch restitution owed after $returnedQty of $originalQty
     * has been returned: min(allocation, trunc4(allocation × returned ÷ original)).
     *
     * @param  numeric-string  $allocQty
     * @param  numeric-string  $returnedQty
     * @param  numeric-string  $originalQty
     * @return numeric-string
     */
    private function cumulativeBatchRestitution(
        string $allocQty,
        string $returnedQty,
        string $originalQty,
    ): string {
        // bcdiv truncates toward zero — conservative: batch restitution may
        // momentarily lag the aggregate by < 0.0001 per allocation, but never
        // leads it, and converges exactly at full return.
        $cumulative = bcdiv(bcmul($allocQty, $returnedQty, 8), $originalQty, 4); // precision-ok: 8 = intermediate headroom, 4 = canonical quantity storage scale

        if (bccomp($cumulative, $allocQty, 4) > 0) { // precision-ok: 4 = canonical quantity storage scale
            return $allocQty;
        }

        return $cumulative;
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
}
